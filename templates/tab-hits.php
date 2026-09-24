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
?>

<form method="get" class="cw-filters" data-cw-filters>
    <input type="hidden" name="page" value="<?php echo esc_attr(ClickWarden_Admin::PAGE); ?>">
    <input type="hidden" name="tab" value="hits">
    <?php if ('' !== $f['ga']) : ?>
        <input type="hidden" name="ga" value="<?php echo esc_attr($f['ga']); ?>">
        <p class="cw-chip">
            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
            <?php
            /* translators: %s: Google Analytics browser id */
            echo esc_html(sprintf(__('Showing one browser: %s', 'clickwarden'), $f['ga']));
            ?>
            <a href="<?php echo esc_url(remove_query_arg(['ga', 'paged'])); ?>" aria-label="<?php esc_attr_e('Remove browser filter', 'clickwarden'); ?>">&times;</a>
        </p>
    <?php endif; ?>

    <div class="cw-filters__row">
        <label class="cw-field">
            <span><?php esc_html_e('From', 'clickwarden'); ?></span>
            <input type="date" name="date_from" value="<?php echo esc_attr($f['date_from']); ?>">
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('To', 'clickwarden'); ?></span>
            <input type="date" name="date_to" value="<?php echo esc_attr($f['date_to']); ?>">
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('IP address', 'clickwarden'); ?></span>
            <input type="search" name="ip" value="<?php echo esc_attr($f['ip']); ?>" placeholder="85.105.* / 2a02:…" spellcheck="false">
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
        <label class="cw-field">
            <span><?php esc_html_e('Visitor type', 'clickwarden'); ?></span>
            <select name="type">
                <option value=""><?php esc_html_e('All', 'clickwarden'); ?></option>
                <option value="human" <?php selected($f['type'], 'human'); ?>><?php esc_html_e('Humans only', 'clickwarden'); ?></option>
                <option value="bot" <?php selected($f['type'], 'bot'); ?>><?php esc_html_e('Bots only', 'clickwarden'); ?></option>
                <optgroup label="<?php esc_attr_e('Bot category', 'clickwarden'); ?>">
                    <?php foreach (ClickWarden_Bot_Detector::categories() as $slug => $label) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($f['type'], $slug); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Risk', 'clickwarden'); ?></span>
            <select name="risk">
                <option value=""><?php esc_html_e('Any risk', 'clickwarden'); ?></option>
                <option value="watch" <?php selected($f['risk'], 'watch'); ?>><?php esc_html_e('Watch and above', 'clickwarden'); ?></option>
                <option value="suspicious" <?php selected($f['risk'], 'suspicious'); ?>><?php esc_html_e('Suspicious only', 'clickwarden'); ?></option>
            </select>
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Page contains', 'clickwarden'); ?></span>
            <input type="search" name="url" value="<?php echo esc_attr($f['url']); ?>" placeholder="/contact">
        </label>
        <label class="cw-field">
            <span><?php esc_html_e('Campaign', 'clickwarden'); ?></span>
            <input type="search" name="campaign" value="<?php echo esc_attr($f['campaign']); ?>" placeholder="<?php esc_attr_e('Name or ID', 'clickwarden'); ?>">
        </label>
        <label class="cw-check">
            <input type="checkbox" name="ad" value="1" <?php checked($f['ad'], '1'); ?>>
            <span><?php esc_html_e('Google Ads clicks only', 'clickwarden'); ?></span>
        </label>
    </div>

    <div class="cw-filters__actions">
        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'clickwarden'); ?></button>
        <?php if (ClickWarden_Admin::has_filters($f)) : ?>
            <a class="button" href="<?php echo esc_url(ClickWarden_Admin::url(['tab' => 'hits'])); ?>"><?php esc_html_e('Clear filters', 'clickwarden'); ?></a>
        <?php endif; ?>
        <button type="submit" class="button" form="cw-export-hits">
            <span class="dashicons dashicons-download" aria-hidden="true"></span>
            <?php esc_html_e('Export CSV', 'clickwarden'); ?>
        </button>
        <span class="cw-filters__count">
            <?php
            $total = (int) $table->get_pagination_arg('total_items');
            /* translators: %s: number of visits */
            echo esc_html(sprintf(_n('%s visit', '%s visits', $total, 'clickwarden'), number_format_i18n($total)));
            ?>
        </span>
    </div>
</form>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cw-export-hits" hidden>
    <?php wp_nonce_field('clickwarden_export'); ?>
    <input type="hidden" name="action" value="clickwarden_export">
    <input type="hidden" name="export" value="hits">
    <?php foreach ($f as $key => $value) : ?>
        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
    <?php endforeach; ?>
</form>

<div class="cw-table">
    <?php $table->display(); ?>
</div>
