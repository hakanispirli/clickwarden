<?php
/**
 * Plugin Name:       ClickWarden – Click Fraud Protection for Google Ads
 * Plugin URI:        https://webmarka.com/clickwarden
 * Description:       Detects invalid and fraudulent Google Ads clicks on your own server and keeps your campaign IP exclusions up to date automatically. Free, self-hosted, cache friendly.
 * Version:           3.0.0
 * Author:            Webmarka
 * Author URI:        https://webmarka.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       clickwarden
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      8.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLICKWARDEN_VERSION', '3.0.0');
define('CLICKWARDEN_DB_VERSION', '1');
define('CLICKWARDEN_PLUGIN_FILE', __FILE__);
define('CLICKWARDEN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLICKWARDEN_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-settings.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-database.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-bot-detector.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-ip-info.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-risk-scorer.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-tracker.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-rest.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-ads-sync.php';
require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-admin.php';

final class ClickWarden {
    private const CRON_EVENTS = [
        'clickwarden_geo_queue' => 'clickwarden_every_minute',
        'clickwarden_rescore'   => 'hourly',
        'clickwarden_purge'     => 'daily',
    ];

    private static ?ClickWarden $instance = null;
    private ClickWarden_Database $database;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->database = new ClickWarden_Database();

        (new ClickWarden_Tracker())->register();
        (new ClickWarden_REST($this->database))->register();
        (new ClickWarden_Ads_Sync($this->database))->register();

        if (is_admin()) {
            (new ClickWarden_Admin($this->database))->register();
        }

        add_action('init', [$this, 'load_textdomain']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'schedule_events']);
        add_action('clickwarden_geo_queue', [$this, 'run_geo_queue']);
        add_action('clickwarden_rescore', [$this, 'run_rescore']);
        add_action('clickwarden_purge', [$this, 'run_purge']);
        add_action('admin_init', [$this, 'add_privacy_policy_content']);
    }

    public function db(): ClickWarden_Database {
        return $this->database;
    }

    public static function activate(): void {
        $instance = self::get_instance();
        $instance->install();
        $instance->schedule_events();
    }

    public static function deactivate(): void {
        foreach (array_keys(self::CRON_EVENTS) as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function load_textdomain(): void {
        // Loads the bundled translations; translate.wordpress.org language packs take precedence once available.
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
        load_plugin_textdomain('clickwarden', false, dirname(plugin_basename(CLICKWARDEN_PLUGIN_FILE)) . '/languages');
    }

    public function maybe_upgrade(): void {
        if (get_option('clickwarden_db_version') !== CLICKWARDEN_DB_VERSION) {
            $this->install();
        }
    }

    private function install(): void {
        $migrated = $this->database->migrate_from_visitor_tracker();
        $this->database->create_tables();
        if ($migrated) {
            $this->database->recount_ad_clicks();
        }
        update_option('clickwarden_db_version', CLICKWARDEN_DB_VERSION, false);

        if (!get_option('clickwarden_installed_at')) {
            update_option('clickwarden_installed_at', time(), false);
        }
    }

    public function cron_schedules(array $schedules): array {
        $schedules['clickwarden_every_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __('Every minute (ClickWarden)', 'clickwarden'),
        ];
        return $schedules;
    }

    public function schedule_events(): void {
        foreach (self::CRON_EVENTS as $hook => $recurrence) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + MINUTE_IN_SECONDS, $recurrence, $hook);
            }
        }
    }

    public function run_geo_queue(): void {
        ClickWarden_IP_Info::process_queue($this->database);
    }

    public function run_rescore(): void {
        $since = gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS);
        foreach ($this->database->get_active_ips($since, 2000) as $ip) {
            ClickWarden_Risk_Scorer::rescore($ip, $this->database);
        }
    }

    public function run_purge(): void {
        $this->database->purge((int) ClickWarden_Settings::get('retention_days'));
    }

    public function add_privacy_policy_content(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = sprintf(
            /* translators: %d: retention period in days */
            __('To protect our advertising campaigns against invalid and fraudulent clicks, we record the IP address, browser user agent, visited page, referrer, advertising click identifiers (gclid, gbraid, wbraid, gad_campaignid, utm parameters), the identifiers stored in existing Google Analytics and Google Ads cookies (_ga, _gcl_au, _gcl_aw, _gcl_gb, _gcl_gs) and basic engagement data (time on page, scroll depth, whether the page was interacted with) of our visitors. If enabled, the IP address is sent to ipapi.is (and optionally ip-api.com) to look up its approximate location and network type. This processing is based on our legitimate interest in preventing ad fraud. The data is kept for %d days and then deleted automatically; IP addresses identified as fraudulent may be kept longer and shared with Google Ads as an IP exclusion list.', 'clickwarden'),
            (int) ClickWarden_Settings::get('retention_days')
        );

        wp_add_privacy_policy_content('ClickWarden', wp_kses_post(wpautop($content, false)));
    }
}

function clickwarden(): ClickWarden {
    return ClickWarden::get_instance();
}

register_activation_hook(__FILE__, ['ClickWarden', 'activate']);
register_deactivation_hook(__FILE__, ['ClickWarden', 'deactivate']);

clickwarden();
