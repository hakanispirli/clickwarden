<?php
/**
 * @var array $data
 */

if (!defined('ABSPATH')) {
    exit;
}

// Included from ClickWarden_Admin::render_page(), so these variables are local to that method.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$s = $data['settings'];
$name = static fn(string $key): string => ClickWarden_Settings::OPTION . '[' . $key . ']';
$remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

// Listed in the order of ClickWarden_IP_Info::providers(); neither is preferred.
$providers = [
    'ipapiis'    => [
        'key'     => 'ipapiis_key',
        'text'    => __('Free: 1,000 lookups a day, commercial use allowed. A key is optional; a free key gives you your own daily quota.', 'clickwarden'),
        'terms'   => 'https://ipapi.is/terms.html',
        'privacy' => 'https://ipapi.is/privacy.html',
    ],
    'proxycheck' => [
        'key'     => 'proxycheck_key',
        'text'    => __('Free: 100 lookups a day without a key, 1,000 a day with a free key, commercial use allowed.', 'clickwarden'),
        'terms'   => 'https://proxycheck.io/terms',
        'privacy' => 'https://proxycheck.io/privacy',
    ],
];
?>

<div class="cw-settings">
    <form method="post" action="options.php" class="cw-panel">
        <?php settings_fields(ClickWarden_Settings::GROUP); ?>

        <h2><?php esc_html_e('Risk scoring', 'clickwarden'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cw-target"><?php esc_html_e('Target countries', 'clickwarden'); ?></label></th>
                <td>
                    <input id="cw-target" type="text" class="regular-text" name="<?php echo esc_attr($name('target_countries')); ?>" value="<?php echo esc_attr($s['target_countries']); ?>" placeholder="US, CA">
                    <p class="description"><?php esc_html_e('Two-letter country codes your campaigns target, comma separated. Clicks from other countries add risk. Leave empty to disable.', 'clickwarden'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Repeated clicks', 'clickwarden'); ?></th>
                <td>
                    <?php
                    printf(
                        /* translators: 1: click count input, 2: hours input */
                        esc_html__('%1$s or more ad clicks from the same IP or browser within %2$s hours', 'clickwarden'),
                        '<input type="number" class="small-text" min="2" max="50" name="' . esc_attr($name('click_threshold')) . '" value="' . esc_attr($s['click_threshold']) . '" aria-label="' . esc_attr__('Click threshold', 'clickwarden') . '">',
                        '<input type="number" class="small-text" min="1" max="720" name="' . esc_attr($name('click_window_hours')) . '" value="' . esc_attr($s['click_window_hours']) . '" aria-label="' . esc_attr__('Time window in hours', 'clickwarden') . '">'
                    );
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Score thresholds', 'clickwarden'); ?></th>
                <td>
                    <label>
                        <?php esc_html_e('Suspicious from', 'clickwarden'); ?>
                        <input type="number" class="small-text" min="10" max="100" name="<?php echo esc_attr($name('suspicious_score')); ?>" value="<?php echo esc_attr($s['suspicious_score']); ?>">
                    </label>
                    &nbsp;
                    <label>
                        <?php esc_html_e('Watch from', 'clickwarden'); ?>
                        <input type="number" class="small-text" min="5" max="99" name="<?php echo esc_attr($name('watch_score')); ?>" value="<?php echo esc_attr($s['watch_score']); ?>">
                    </label>
                    <p class="description"><?php esc_html_e('Scores range from 0 to 100. Only suspicious IPs that clicked an ad are added to the Google Ads exclusion list.', 'clickwarden'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cw-rate"><?php esc_html_e('Request limit', 'clickwarden'); ?></label></th>
                <td>
                    <input id="cw-rate" type="number" class="small-text" min="10" max="1000" name="<?php echo esc_attr($name('rate_limit')); ?>" value="<?php echo esc_attr($s['rate_limit']); ?>">
                    <?php esc_html_e('page views per minute per IP', 'clickwarden'); ?>
                    <p class="description"><?php esc_html_e('Requests above this limit are not recorded and add risk to the IP.', 'clickwarden'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Tracking', 'clickwarden'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="cw-retention"><?php esc_html_e('Keep data for', 'clickwarden'); ?></label></th>
                <td>
                    <input id="cw-retention" type="number" class="small-text" min="1" max="365" name="<?php echo esc_attr($name('retention_days')); ?>" value="<?php echo esc_attr($s['retention_days']); ?>">
                    <?php esc_html_e('days', 'clickwarden'); ?>
                    <p class="description"><?php esc_html_e('Older visits are deleted automatically every day. Whitelisted and flagged IPs are kept.', 'clickwarden'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cw-excluded"><?php esc_html_e('Excluded IPs', 'clickwarden'); ?></label></th>
                <td>
                    <textarea id="cw-excluded" class="large-text code" rows="3" name="<?php echo esc_attr($name('excluded_ips')); ?>"><?php echo esc_textarea($s['excluded_ips']); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Your office or team IPs are never recorded. One IP or CIDR range (e.g. 88.1.2.0/24) per line.', 'clickwarden'); ?>
                        <?php if ($remote_addr) : ?>
                            <br>
                            <?php
                            /* translators: %s: current IP address */
                            printf(esc_html__('Your current IP: %s', 'clickwarden'), '<code>' . esc_html($remote_addr) . '</code>');
                            ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="cw-proxies"><?php esc_html_e('Trusted proxies', 'clickwarden'); ?></label></th>
                <td>
                    <textarea id="cw-proxies" class="large-text code" rows="2" name="<?php echo esc_attr($name('trusted_proxies')); ?>"><?php echo esc_textarea($s['trusted_proxies']); ?></textarea>
                    <p class="description"><?php esc_html_e('Leave empty unless the site is behind a CDN or reverse proxy. The X-Forwarded-For header is only trusted for requests coming from these IPs/ranges; otherwise visitors could fake their IP address.', 'clickwarden'); ?></p>
                </td>
            </tr>
        </table>

        <h2 id="cw-geo"><?php esc_html_e('Network lookups', 'clickwarden'); ?></h2>
        <div class="cw-callout">
            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
            <div>
                <p><strong><?php esc_html_e('How network lookups work', 'clickwarden'); ?></strong></p>
                <ul>
                    <li><?php esc_html_e('Visits are recorded on your own server. No IP address leaves your site until you enable lookups.', 'clickwarden'); ?></li>
                    <li><?php esc_html_e('Once a minute, a background task sends new visitor IP addresses in batches to the provider you choose. Only the IP address is sent, plus your API key if you entered one.', 'clickwarden'); ?></li>
                    <li><?php esc_html_e('The answer (country, network and whether the IP belongs to a datacenter, VPN, proxy or Tor) is stored in your database and the risk score is updated right away. These are the strongest click fraud signals.', 'clickwarden'); ?></li>
                    <li><?php esc_html_e('IPs that clicked an ad are looked up first, and each IP is looked up again at most once every 30 days, so a free daily quota goes a long way.', 'clickwarden'); ?></li>
                    <li><?php esc_html_e('If the provider is unreachable or its daily quota is used up, lookups pause and resume automatically.', 'clickwarden'); ?></li>
                </ul>
            </div>
        </div>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('IP lookups', 'clickwarden'); ?></th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr($name('geo_enabled')); ?>" value="0">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($name('geo_enabled')); ?>" value="1" <?php checked($s['geo_enabled'], 1); ?>>
                        <?php esc_html_e('Look up visitor IP addresses with the provider below', 'clickwarden'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Provider', 'clickwarden'); ?></th>
                <td>
                    <fieldset class="cw-providers">
                        <legend class="screen-reader-text"><?php esc_html_e('Provider', 'clickwarden'); ?></legend>
                        <?php foreach (ClickWarden_IP_Info::providers() as $provider_slug => $provider_label) : ?>
                            <?php $provider = $providers[$provider_slug]; ?>
                            <div class="cw-provider">
                                <label class="cw-provider__head">
                                    <input type="radio" name="<?php echo esc_attr($name('geo_provider')); ?>" value="<?php echo esc_attr($provider_slug); ?>" <?php checked($s['geo_provider'], $provider_slug); ?>>
                                    <?php echo esc_html($provider_label); ?>
                                </label>
                                <p class="description">
                                    <?php echo esc_html($provider['text']); ?>
                                    <a href="<?php echo esc_url($provider['terms']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Terms', 'clickwarden'); ?></a> ·
                                    <a href="<?php echo esc_url($provider['privacy']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Privacy policy', 'clickwarden'); ?></a>
                                </p>
                                <p class="cw-provider__key">
                                    <label for="cw-key-<?php echo esc_attr($provider_slug); ?>">
                                        <?php
                                        /* translators: %s: provider name, e.g. proxycheck.io */
                                        echo esc_html(sprintf(__('%s API key (optional)', 'clickwarden'), $provider_label));
                                        ?>
                                    </label><br>
                                    <input id="cw-key-<?php echo esc_attr($provider_slug); ?>" type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr($name($provider['key'])); ?>" value="<?php echo esc_attr($s[$provider['key']]); ?>">
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Fallback provider', 'clickwarden'); ?></th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr($name('ipapi_fallback')); ?>" value="0">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($name('ipapi_fallback')); ?>" value="1" <?php checked($s['ipapi_fallback'], 1); ?>>
                        <?php esc_html_e('Use ip-api.com when the selected provider is unavailable or its daily quota is used up', 'clickwarden'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('The free ip-api.com endpoint is HTTP only and for non-commercial use. Enter a Pro key below for HTTPS and commercial use.', 'clickwarden'); ?>
                        <a href="https://ip-api.com/docs/legal" target="_blank" rel="noopener"><?php esc_html_e('Terms', 'clickwarden'); ?></a>
                    </p>
                    <p>
                        <label for="cw-key"><?php esc_html_e('ip-api.com Pro key', 'clickwarden'); ?></label><br>
                        <input id="cw-key" type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr($name('ipapi_key')); ?>" value="<?php echo esc_attr($s['ipapi_key']); ?>">
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <aside class="cw-settings__side">
        <section class="cw-panel">
            <h2><?php esc_html_e('Status', 'clickwarden'); ?></h2>
            <dl class="cw-status">
                <dt><?php esc_html_e('IPs waiting for geolocation', 'clickwarden'); ?></dt>
                <dd><?php echo esc_html(number_format_i18n((int) $data['pending_geo'])); ?></dd>
                <dt><?php esc_html_e('Active provider', 'clickwarden'); ?></dt>
                <dd>
                    <?php
                    if (!$s['geo_enabled']) {
                        esc_html_e('Disabled', 'clickwarden');
                    } elseif (!$data['paused']['primary']) {
                        echo esc_html(ClickWarden_IP_Info::primary_label());
                    } elseif ($s['ipapi_fallback'] && !$data['paused']['ipapi']) {
                        /* translators: 1: selected provider name, 2: time when it is retried */
                        echo esc_html(sprintf(__('ip-api.com (%1$s retried at %2$s)', 'clickwarden'), ClickWarden_IP_Info::primary_label(), wp_date('H:i', $data['paused']['primary'])));
                    } else {
                        esc_html_e('Paused, retrying shortly', 'clickwarden');
                    }
                    ?>
                </dd>
                <dt><?php esc_html_e('Next geolocation run', 'clickwarden'); ?></dt>
                <dd><?php echo $data['next_geo'] ? esc_html(wp_date('H:i:s', $data['next_geo'])) : esc_html__('Not scheduled', 'clickwarden'); ?></dd>
                <dt><?php esc_html_e('Next cleanup', 'clickwarden'); ?></dt>
                <dd><?php echo $data['next_purge'] ? esc_html(wp_date(get_option('date_format') . ' H:i', $data['next_purge'])) : esc_html__('Not scheduled', 'clickwarden'); ?></dd>
            </dl>
            <?php if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) : ?>
                <p class="cw-muted">
                    <?php esc_html_e('WP-Cron only runs when the site gets traffic. For reliable lookups, add a cron job in your hosting control panel that runs every minute:', 'clickwarden'); ?>
                </p>
                <code class="cw-code-block">wget -q -O /dev/null "<?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?>"</code>
            <?php endif; ?>
        </section>

        <section class="cw-panel">
            <h2><?php esc_html_e('Page cache & CDN', 'clickwarden'); ?></h2>
            <p class="cw-muted"><?php esc_html_e('The tracker runs in the browser, so cached pages are tracked too. After installing or updating ClickWarden, purge your page cache and your CDN once, otherwise cached pages are served without the tracker. The script is excluded from LiteSpeed Cache JS optimization automatically; with other optimization plugins, exclude clickwarden/assets/js/tracker.js from combining and delaying.', 'clickwarden'); ?></p>
        </section>

        <section class="cw-panel cw-panel--danger">
            <h2><?php esc_html_e('Clean up', 'clickwarden'); ?></h2>
            <p class="cw-muted">
                <?php
                /* translators: %d: retention period in days */
                echo esc_html(sprintf(__('Delete visits older than %d days now.', 'clickwarden'), (int) $s['retention_days']));
                ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  data-cw-confirm="<?php esc_attr_e('Delete old visits now? This cannot be undone.', 'clickwarden'); ?>">
                <?php wp_nonce_field('clickwarden_purge'); ?>
                <input type="hidden" name="action" value="clickwarden_purge">
                <button type="submit" class="button cw-button-danger"><?php esc_html_e('Delete old visits', 'clickwarden'); ?></button>
            </form>
        </section>
    </aside>
</div>
