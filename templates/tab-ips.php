<?php
/**
 * @var array $data
 */

if (!defined('ABSPATH')) {
    exit;
}

// Included from ClickWarden_Admin::render_page(), so these variables are local to that method.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$f = $data['filters'];
$table = $data['table'];
$exclusions = $data['exclusions'];
$ranges = $data['ranges'];
?>

<section class="cw-panel cw-export">
    <header class="cw-panel__head">
        <div>
            <h2><?php esc_html_e('Google Ads IP exclusion list', 'clickwarden'); ?></h2>
            <p class="cw-muted">
                <?php
                printf(
                    /* translators: %s: number of IP addresses */
                    esc_html(_n('%s suspicious IP that clicked your ads, ordered by risk.', '%s suspicious IPs that clicked your ads, ordered by risk.', count($exclusions), 'clickwarden')),
                    '<strong>' . esc_html(number_format_i18n(count($exclusions))) . '</strong>'
                );
                ?>
                <?php esc_html_e('Google Ads accepts up to 500 excluded IPs per campaign: Campaign → Settings → Additional settings → IP exclusions.', 'clickwarden'); ?>
            </p>
        </div>
        <div class="cw-export__buttons">
            <button type="button" class="button button-primary" data-cw-copy-target="#cw-exclusions" <?php disabled(empty($exclusions)); ?>>
                <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                <?php esc_html_e('Copy list', 'clickwarden'); ?>
            </button>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('clickwarden_export'); ?>
                <input type="hidden" name="action" value="clickwarden_export">
                <input type="hidden" name="export" value="ads">
                <button type="submit" class="button" <?php disabled(empty($exclusions)); ?>>
                    <span class="dashicons dashicons-download" aria-hidden="true"></span>
                    <?php esc_html_e('Download .txt', 'clickwarden'); ?>
                </button>
            </form>
        </div>
    </header>

    <?php if ($exclusions) : ?>
        <textarea id="cw-exclusions" class="cw-exclusions" rows="5" readonly><?php echo esc_textarea(implode("\n", $exclusions)); ?></textarea>
    <?php else : ?>
        <p class="cw-empty"><?php esc_html_e('No suspicious IPs yet. IPs appear here once they click an ad and exceed the suspicious score.', 'clickwarden'); ?></p>
    <?php endif; ?>

    <?php if ($ranges) : ?>
        <div class="cw-ranges">
            <strong><?php esc_html_e('Range suggestions:', 'clickwarden'); ?></strong>
            <?php foreach ($ranges as $range => $count) : ?>
                <span class="cw-tag cw-tag--danger">
                    <code><?php echo esc_html($range); ?></code>
                    <?php
                    /* translators: %d: number of suspicious IPs in the range */
                    echo esc_html(sprintf(_n('%d IP', '%d IPs', $count, 'clickwarden'), $count));
                    ?>
                </span>
            <?php endforeach; ?>
            <p class="cw-muted"><?php esc_html_e('Several suspicious IPs share these /24 blocks. Excluding the whole block saves slots in the 500 IP limit, but may also block real customers on the same network.', 'clickwarden'); ?></p>
        </div>
    <?php endif; ?>
</section>

<form method="get" class="cw-filters" data-cw-filters>
    <input type="hidden" name="page" value="<?php echo esc_attr(ClickWarden_Admin::PAGE); ?>">
    <input type="hidden" name="tab" value="ips">

    <div class="cw-filters__row">
        <label class="cw-field">
            <span><?php esc_html_e('IP address', 'clickwarden'); ?></span>
            <input type="search" name="ip" value="<?php echo esc_attr($f['ip']); ?>" placeholder="85.105.*" spellcheck="false">
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Risk level', 'clickwarden'); ?></span>
            <select name="level">
                <option value=""><?php esc_html_e('All levels', 'clickwarden'); ?></option>
                <option value="suspicious" <?php selected($f['level'], 'suspicious'); ?>><?php esc_html_e('Suspicious', 'clickwarden'); ?></option>
                <option value="watch" <?php selected($f['level'], 'watch'); ?>><?php esc_html_e('Watch', 'clickwarden'); ?></option>
                <option value="clean" <?php selected($f['level'], 'clean'); ?>><?php esc_html_e('Clean', 'clickwarden'); ?></option>
            </select>
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Status', 'clickwarden'); ?></span>
            <select name="status">
                <option value=""><?php esc_html_e('All', 'clickwarden'); ?></option>
                <option value="none" <?php selected($f['status'], 'none'); ?>><?php esc_html_e('Automatic', 'clickwarden'); ?></option>
                <option value="flagged" <?php selected($f['status'], 'flagged'); ?>><?php esc_html_e('Flagged manually', 'clickwarden'); ?></option>
                <option value="whitelisted" <?php selected($f['status'], 'whitelisted'); ?>><?php esc_html_e('Whitelisted', 'clickwarden'); ?></option>
            </select>
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Network', 'clickwarden'); ?></span>
            <select name="network">
                <option value=""><?php esc_html_e('All networks', 'clickwarden'); ?></option>
                <option value="hosting" <?php selected($f['network'], 'hosting'); ?>><?php esc_html_e('Datacenter / hosting', 'clickwarden'); ?></option>
                <option value="proxy" <?php selected($f['network'], 'proxy'); ?>><?php esc_html_e('VPN / proxy', 'clickwarden'); ?></option>
                <option value="mobile" <?php selected($f['network'], 'mobile'); ?>><?php esc_html_e('Mobile network', 'clickwarden'); ?></option>
                <option value="residential" <?php selected($f['network'], 'residential'); ?>><?php esc_html_e('Residential', 'clickwarden'); ?></option>
            </select>
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Country', 'clickwarden'); ?></span>
            <select name="country">
                <option value=""><?php esc_html_e('All countries', 'clickwarden'); ?></option>
                <?php foreach ($data['countries'] as $country) : ?>
                    <option value="<?php echo esc_attr($country->country_code); ?>" <?php selected($f['country'], $country->country_code); ?>>
                        <?php echo esc_html($country->country_code . ' – ' . $country->country); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="cw-field cw-field--narrow">
            <span><?php esc_html_e('Min. ad clicks', 'clickwarden'); ?></span>
            <input type="number" name="min_clicks" min="0" max="1000" value="<?php echo esc_attr($f['min_clicks']); ?>">
        </label>
        <label class="cw-check">
            <input type="checkbox" name="ads" value="1" <?php checked($f['ads'], '1'); ?>>
            <span><?php esc_html_e('Blocked in Google Ads only', 'clickwarden'); ?></span>
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Seen from', 'clickwarden'); ?></span>
            <input type="date" name="date_from" value="<?php echo esc_attr($f['date_from']); ?>">
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Seen until', 'clickwarden'); ?></span>
            <input type="date" name="date_to" value="<?php echo esc_attr($f['date_to']); ?>">
        </label>
    </div>

    <div class="cw-filters__actions">
        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'clickwarden'); ?></button>
        <?php if (ClickWarden_Admin::has_filters($f)) : ?>
            <a class="button" href="<?php echo esc_url(ClickWarden_Admin::url(['tab' => 'ips'])); ?>"><?php esc_html_e('Clear filters', 'clickwarden'); ?></a>
        <?php endif; ?>
        <button type="submit" class="button" form="cw-export-ips">
            <span class="dashicons dashicons-download" aria-hidden="true"></span>
            <?php esc_html_e('Export CSV', 'clickwarden'); ?>
        </button>
        <span class="cw-filters__count">
            <?php
            $total = (int) $table->get_pagination_arg('total_items');
            /* translators: %s: number of IP addresses */
            echo esc_html(sprintf(_n('%s IP address', '%s IP addresses', $total, 'clickwarden'), number_format_i18n($total)));
            ?>
        </span>
    </div>
</form>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cw-export-ips" hidden>
    <?php wp_nonce_field('clickwarden_export'); ?>
    <input type="hidden" name="action" value="clickwarden_export">
    <input type="hidden" name="export" value="ips">
    <?php foreach ($f as $key => $value) : ?>
        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
    <?php endforeach; ?>
</form>

<form method="post" class="cw-table">
    <?php $table->display(); ?>
</form>
