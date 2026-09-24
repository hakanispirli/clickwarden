<?php

if (!defined('ABSPATH')) {
    exit;
}

class ClickWarden_Admin {
    public const PAGE = 'clickwarden';
    private const PER_PAGE_OPTION = 'clickwarden_per_page';

    private string $hook = '';

    public function __construct(private ClickWarden_Database $database) {}

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [ClickWarden_Settings::class, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_post_clickwarden_export', [$this, 'handle_export']);
        add_action('admin_post_clickwarden_purge', [$this, 'handle_purge']);
        add_action('admin_post_clickwarden_ip_action', [$this, 'handle_ip_action']);
        add_action('admin_post_clickwarden_ads_token', [$this, 'handle_ads_token']);
        add_filter('set_screen_option_' . self::PER_PAGE_OPTION, [$this, 'save_per_page'], 10, 3);
        add_filter('plugin_action_links_' . plugin_basename(CLICKWARDEN_PLUGIN_FILE), [$this, 'action_links']);
        add_filter('admin_footer_text', [$this, 'footer_text']);
    }

    /**
     * Shield with a cursor; WordPress recolors the fill to match the admin color scheme.
     */
    public static function icon_svg(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1 3 3.6v5.2c0 4.3 2.9 8.3 7 9.6 4.1-1.3 7-5.3 7-9.6V3.6L10 1Zm-1.4 5 5.9 3.1-2.5.8 1.6 2.9-1.1.6-1.6-2.9-1.8 1.8L8.6 6Z"/></svg>';
    }

    public function footer_text($text) {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== $this->hook) {
            return $text;
        }
        return sprintf(
            /* translators: %s: link to Webmarka */
            esc_html__('ClickWarden is free and open source, made with care by %s.', 'clickwarden'),
            '<a href="https://webmarka.com/?utm_source=clickwarden&amp;utm_medium=plugin&amp;utm_campaign=footer" target="_blank" rel="noopener">Webmarka</a>'
        );
    }

    public function add_menu(): void {
        $this->hook = (string) add_menu_page(
            __('ClickWarden', 'clickwarden'),
            __('ClickWarden', 'clickwarden'),
            'manage_options',
            self::PAGE,
            [$this, 'render_page'],
            'data:image/svg+xml;base64,' . base64_encode(self::icon_svg()),
            30
        );

        add_action('load-' . $this->hook, [$this, 'on_load']);
    }

    public function action_links(array $links): array {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::url(['tab' => 'settings'])),
            esc_html__('Settings', 'clickwarden')
        ));
        return $links;
    }

    public static function url(array $args = []): string {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }

    public static function tabs(): array {
        return [
            'overview' => __('Overview', 'clickwarden'),
            'hits'     => __('Visits', 'clickwarden'),
            'ips'      => __('IP Addresses', 'clickwarden'),
            'ads'      => __('Google Ads', 'clickwarden'),
            'settings' => __('Settings', 'clickwarden'),
        ];
    }

    public static function current_tab(): string {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
        return array_key_exists($tab, self::tabs()) ? $tab : 'overview';
    }

    public function on_load(): void {
        if (in_array(self::current_tab(), ['hits', 'ips'], true)) {
            add_screen_option('per_page', [
                'label'   => __('Rows per page', 'clickwarden'),
                'default' => 50,
                'option'  => self::PER_PAGE_OPTION,
            ]);
        }

        if ('ips' === self::current_tab()) {
            $this->process_bulk_action();
        }
    }

    public function save_per_page($status, string $option, $value) {
        return max(10, min(500, (int) $value));
    }

    public static function per_page(): int {
        $value = (int) get_user_option(self::PER_PAGE_OPTION);
        return $value > 0 ? $value : 50;
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== $this->hook) {
            return;
        }

        wp_enqueue_style('clickwarden-admin', CLICKWARDEN_PLUGIN_URL . 'assets/css/admin.css', [], CLICKWARDEN_VERSION);
        wp_enqueue_script(
            'clickwarden-admin',
            CLICKWARDEN_PLUGIN_URL . 'assets/js/admin.js',
            [],
            CLICKWARDEN_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );
    }

    /* ------------------------------------------------------------------
     * Filters
     * ---------------------------------------------------------------- */

    /**
     * Reads and validates list filters from the request.
     */
    public static function read_filters(string $type, ?array $source = null): array {
        $source ??= $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
        $raw = static fn(string $key): string => isset($source[$key]) && is_scalar($source[$key])
            ? trim(sanitize_text_field(wp_unslash((string) $source[$key])))
            : '';

        $date = static fn(string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        $ip = preg_replace('/[^0-9A-Fa-f:.*]/', '', $raw('ip'));
        $country = strtoupper($raw('country'));

        $filters = [
            'ip'        => $ip,
            'country'   => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
            'date_from' => $date($raw('date_from')),
            'date_to'   => $date($raw('date_to')),
        ];

        if ('hits' === $type) {
            $types = array_merge(['human', 'bot'], array_keys(ClickWarden_Bot_Detector::categories()));
            $filters += [
                'type'     => in_array($raw('type'), $types, true) ? $raw('type') : '',
                'ad'       => '1' === $raw('ad') ? '1' : '',
                'risk'     => in_array($raw('risk'), ['suspicious', 'watch'], true) ? $raw('risk') : '',
                'url'      => mb_substr($raw('url'), 0, 100),
                'campaign' => mb_substr($raw('campaign'), 0, 100),
                'ga'       => preg_match('/^\d{1,20}\.\d{1,20}$/', $raw('ga')) ? $raw('ga') : '',
            ];
        } else {
            $filters += [
                'level'      => in_array($raw('level'), ['suspicious', 'watch', 'clean'], true) ? $raw('level') : '',
                'status'     => in_array($raw('status'), ['none', 'whitelisted', 'flagged'], true) ? $raw('status') : '',
                'network'    => in_array($raw('network'), ['hosting', 'proxy', 'mobile', 'residential'], true) ? $raw('network') : '',
                'min_clicks' => (int) $raw('min_clicks') > 0 ? (string) min(1000, (int) $raw('min_clicks')) : '',
                'ads'        => '1' === $raw('ads') ? '1' : '',
            ];
        }

        return $filters;
    }

    public static function has_filters(array $filters): bool {
        return (bool) array_filter($filters, static fn($v) => '' !== $v);
    }

    /* ------------------------------------------------------------------
     * Rendering
     * ---------------------------------------------------------------- */

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'clickwarden'));
        }

        $tab = self::current_tab();
        $db = $this->database;
        $data = [];

        switch ($tab) {
            case 'overview':
                $data['overview'] = $db->get_overview();
                $data['setup'] = $this->setup_steps();
                break;

            case 'hits':
                require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-list-table-hits.php';
                $data['filters'] = self::read_filters('hits');
                $data['table'] = new ClickWarden_List_Table_Hits($db, $data['filters']);
                $data['table']->prepare_items();
                $data['countries'] = $db->get_countries();
                break;

            case 'ips':
                require_once CLICKWARDEN_PLUGIN_DIR . 'includes/class-list-table-ips.php';
                $data['filters'] = self::read_filters('ips');
                $data['table'] = new ClickWarden_List_Table_IPs($db, $data['filters']);
                $data['table']->prepare_items();
                $data['countries'] = $db->get_countries();
                $data['exclusions'] = $db->get_exclusion_ips(500);
                $data['ranges'] = self::suggest_ranges($data['exclusions']);
                break;

            case 'ads':
                $data['settings'] = ClickWarden_Settings::all();
                $data['status'] = ClickWarden_Ads_Sync::status();
                $data['script'] = ClickWarden_Ads_Sync::script();
                $data['queued'] = count($db->get_exclusion_ips(ClickWarden_Ads_Sync::LIMIT));
                $data['excluded'] = $db->count_ads_excluded();
                break;

            case 'settings':
                $data['settings'] = ClickWarden_Settings::all();
                $data['pending_geo'] = $db->count_geo_pending();
                $data['next_geo'] = wp_next_scheduled('clickwarden_geo_queue');
                $data['next_purge'] = wp_next_scheduled('clickwarden_purge');
                $data['paused'] = [
                    'primary' => ClickWarden_IP_Info::primary_paused_until(),
                    'ipapi'   => (int) get_transient(ClickWarden_IP_Info::BACKOFF_IPAPI),
                ];
                break;
        }

        include CLICKWARDEN_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Onboarding checklist shown on the overview until every step is done.
     */
    private function setup_steps(): array {
        $last_hit = $this->database->get_last_hit_time();
        $sync = ClickWarden_Ads_Sync::status();

        return [
            [
                'done'  => $last_hit && strtotime($last_hit . ' UTC') > time() - DAY_IN_SECONDS,
                'title' => __('Receive your first visits', 'clickwarden'),
                'text'  => __('Open your site in a private window. If nothing shows up, purge your page cache and CDN.', 'clickwarden'),
                'url'   => self::url(['tab' => 'hits']),
            ],
            [
                'done'  => (bool) ClickWarden_Settings::get('excluded_ips'),
                'title' => __('Exclude your own IP address', 'clickwarden'),
                'text'  => __('Your office and team clicks should never count as fraud.', 'clickwarden'),
                'url'   => self::url(['tab' => 'settings']) . '#cw-excluded',
            ],
            [
                'done'  => (bool) ClickWarden_Settings::get('geo_enabled'),
                'title' => __('Enable network lookups', 'clickwarden'),
                'text'  => __('Detects datacenter, VPN and proxy IPs, the strongest fraud signals.', 'clickwarden'),
                'url'   => self::url(['tab' => 'settings']) . '#cw-geo',
            ],
            [
                'done'  => !empty($sync['time']) && $sync['time'] > time() - 3 * HOUR_IN_SECONDS,
                'title' => __('Connect Google Ads', 'clickwarden'),
                'text'  => __('Install the script once; suspicious IPs are then excluded from your campaigns every hour.', 'clickwarden'),
                'url'   => self::url(['tab' => 'ads']),
            ],
        ];
    }

    /**
     * Suggests /24 wildcard ranges when three or more suspicious IPv4 addresses share a block.
     */
    public static function suggest_ranges(array $ips): array {
        $blocks = [];
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $block = implode('.', array_slice(explode('.', $ip), 0, 3)) . '.*';
                $blocks[$block] = ($blocks[$block] ?? 0) + 1;
            }
        }
        $blocks = array_filter($blocks, static fn($count) => $count >= 3);
        arsort($blocks);
        return $blocks;
    }

    public static function format_date(?string $utc, string $format = ''): string {
        if (empty($utc)) {
            return '—';
        }
        $format = $format ?: get_option('date_format') . ' H:i';
        return wp_date($format, strtotime($utc . ' UTC'));
    }

    public static function format_duration(?int $ms): string {
        if (null === $ms) {
            return '—';
        }
        $seconds = (int) round($ms / 1000);
        if ($seconds < 60) {
            /* translators: %d: seconds */
            return sprintf(__('%ds', 'clickwarden'), $seconds);
        }
        /* translators: 1: minutes, 2: seconds */
        return sprintf(__('%1$dm %2$ds', 'clickwarden'), intdiv($seconds, 60), $seconds % 60);
    }

    public static function risk_badge(object $ip): string {
        $level = ClickWarden_Risk_Scorer::level($ip);
        $labels = ClickWarden_Risk_Scorer::level_labels();
        $reasons = ClickWarden_Risk_Scorer::describe_reasons((string) $ip->risk_reasons);

        return sprintf(
            '<span class="cw-risk cw-risk--%1$s" title="%2$s"><span class="cw-risk__score">%3$d</span>%4$s</span>',
            esc_attr($level),
            esc_attr(implode(', ', $reasons)),
            (int) $ip->risk_score,
            esc_html($labels[$level])
        );
    }

    public function notices(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== $this->hook) {
            return;
        }

        // No visits for a day usually means a page cache or CDN serves pages without the tracker.
        $installed = (int) get_option('clickwarden_installed_at');
        $last_hit = $this->database->get_last_hit_time();
        if ($installed && $installed < time() - DAY_IN_SECONDS && (!$last_hit || strtotime($last_hit . ' UTC') < time() - DAY_IN_SECONDS)) {
            wp_admin_notice(
                esc_html__('No visits were recorded in the last 24 hours. If your site gets traffic, purge your page cache and CDN (for example LiteSpeed Cache and your host\'s CDN) so pages include the ClickWarden tracker.', 'clickwarden'),
                ['type' => 'warning']
            );
        }

        if (!empty($_GET['settings-updated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
            wp_admin_notice(esc_html__('Settings saved.', 'clickwarden'), ['type' => 'success', 'dismissible' => true]);
        }

        if (empty($_GET['clickwarden_msg'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
            return;
        }

        $count = isset($_GET['clickwarden_count']) ? absint($_GET['clickwarden_count']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
        $messages = [
            /* translators: %d: number of deleted records */
            'purged'   => sprintf(_n('%d old visit deleted.', '%d old visits deleted.', $count, 'clickwarden'), $count),
            /* translators: %d: number of IP addresses */
            'updated'  => sprintf(_n('%d IP address updated.', '%d IP addresses updated.', $count, 'clickwarden'), $count),
            'no_items' => __('No IP address selected.', 'clickwarden'),
            'token'    => __('New access key created. Paste the updated script into Google Ads, the old key no longer works.', 'clickwarden'),
        ];

        $key = isset($_GET['clickwarden_msg']) ? sanitize_key(wp_unslash($_GET['clickwarden_msg'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
        if (isset($messages[$key])) {
            wp_admin_notice(esc_html($messages[$key]), [
                'type'        => 'no_items' === $key ? 'warning' : 'success',
                'dismissible' => true,
            ]);
        }
    }

    /* ------------------------------------------------------------------
     * Actions
     * ---------------------------------------------------------------- */

    private function check_permission(string $nonce_action): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'clickwarden'), 403);
        }
        check_admin_referer($nonce_action);
    }

    private function redirect(array $args, string $tab): void {
        // Raw referer: bulk actions post back to the same URL, which wp_get_referer() rejects.
        $back = wp_validate_redirect(wp_get_raw_referer(), '') ?: self::url(['tab' => $tab]);
        $back = remove_query_arg(['clickwarden_msg', 'clickwarden_count', 'action', 'action2', 'ips', '_wpnonce', '_wp_http_referer'], $back);
        wp_safe_redirect(add_query_arg($args, $back));
        exit;
    }

    private static function status_for_action(string $action): ?string {
        return match ($action) {
            'whitelist' => 'whitelisted',
            'flag'      => 'flagged',
            'reset'     => 'none',
            default     => null,
        };
    }

    private function apply_status(array $ips, string $status): int {
        $ips = array_values(array_filter(array_map('strval', $ips), static fn($ip) => (bool) filter_var($ip, FILTER_VALIDATE_IP)));
        $count = $this->database->set_ip_status($ips, $status);
        foreach ($ips as $ip) {
            ClickWarden_Risk_Scorer::rescore($ip, $this->database);
        }
        return $count;
    }

    private function process_bulk_action(): void {
        // Only decides whether a bulk action was submitted; the nonce is verified before anything changes.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '-1';
        if ('-1' === $action) {
            $action = isset($_REQUEST['action2']) && is_string($_REQUEST['action2']) ? sanitize_key(wp_unslash($_REQUEST['action2'])) : '';
        }
        // phpcs:enable
        $status = self::status_for_action($action);
        if (null === $status) {
            return;
        }

        $this->check_permission('bulk-ips');

        $ips = isset($_REQUEST['ips']) ? array_map('sanitize_text_field', (array) wp_unslash($_REQUEST['ips'])) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in check_permission().
        if (!$ips) {
            $this->redirect(['clickwarden_msg' => 'no_items'], 'ips');
        }

        $this->redirect(['clickwarden_msg' => 'updated', 'clickwarden_count' => $this->apply_status($ips, $status)], 'ips');
    }

    public function handle_ip_action(): void {
        // The nonce is bound to the IP address, so the IP is read first and verified right after.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $ip = isset($_GET['ip']) ? sanitize_text_field(wp_unslash($_GET['ip'])) : '';
        $this->check_permission('clickwarden_ip_action_' . $ip);

        $status = self::status_for_action(isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '');
        // phpcs:enable
        $count = null !== $status ? $this->apply_status([$ip], $status) : 0;

        $this->redirect(['clickwarden_msg' => 'updated', 'clickwarden_count' => $count], 'ips');
    }

    public static function ip_action_url(string $ip, string $do): string {
        return wp_nonce_url(
            add_query_arg(['action' => 'clickwarden_ip_action', 'do' => $do, 'ip' => $ip], admin_url('admin-post.php')),
            'clickwarden_ip_action_' . $ip
        );
    }

    public function handle_ads_token(): void {
        $this->check_permission('clickwarden_ads_token');
        ClickWarden_Ads_Sync::regenerate_token();
        $this->redirect(['clickwarden_msg' => 'token'], 'ads');
    }

    public function handle_purge(): void {
        $this->check_permission('clickwarden_purge');
        $deleted = $this->database->purge((int) ClickWarden_Settings::get('retention_days'));
        $this->redirect(['clickwarden_msg' => 'purged', 'clickwarden_count' => $deleted], 'settings');
    }

    public function handle_export(): void {
        $this->check_permission('clickwarden_export');

        $type = isset($_POST['export']) ? sanitize_key(wp_unslash($_POST['export'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_permission().
        $date = wp_date('Ymd-His');

        nocache_headers();

        if ('ads' === $type) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="google-ads-ip-exclusions-' . $date . '.txt"');
            echo esc_html(implode("\n", $this->database->get_exclusion_ips(500)));
            exit;
        }

        if (!in_array($type, ['hits', 'ips'], true)) {
            wp_die(esc_html__('Invalid export type.', 'clickwarden'), 400);
        }

        $filters = self::read_filters($type, $_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_permission().
        [$rows] = 'hits' === $type
            ? $this->database->query_hits($filters, 'created_at', 'DESC', 50000, 1)
            : $this->database->query_ips($filters, 'risk_score', 'DESC', 50000, 1);

        $columns = 'hits' === $type
            ? ['created_at', 'ip', 'country_code', 'city', 'isp', 'url_path', 'referrer', 'click_type', 'click_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'is_bot', 'bot_category', 'bot_name', 'is_webdriver', 'engaged_ms', 'interacted', 'scroll_pct', 'risk_score', 'risk_reasons', 'status', 'user_agent']
            : ['ip', 'risk_score', 'risk_reasons', 'status', 'country_code', 'country', 'city', 'isp', 'org', 'asn', 'is_proxy', 'is_hosting', 'is_mobile', 'hits', 'ad_clicks', 'first_seen', 'last_seen', 'note'];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clickwarden-' . $type . '-' . $date . '.csv"');

        // Streams the download to the response body, not to the filesystem.
        // phpcs:disable WordPress.WP.AlternativeFunctions
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = self::csv_safe((string) ($row->$column ?? ''));
            }
            fputcsv($out, $line, ',', '"', '');
        }
        fclose($out);
        // phpcs:enable
        exit;
    }

    /**
     * Neutralises spreadsheet formula injection (user agents and referrers are attacker controlled).
     */
    private static function csv_safe(string $value): string {
        return '' !== $value && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
