<?php
/**
 * @var string               $tab
 * @var array                $data
 * @var ClickWarden_Database $db
 */

if (!defined('ABSPATH')) {
    exit;
}

// Included from ClickWarden_Admin::render_page(), so these variables are local to that method.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tab_icons = [
    'overview' => 'dashicons-chart-area',
    'hits'     => 'dashicons-list-view',
    'ips'      => 'dashicons-admin-site-alt3',
    'ads'      => 'dashicons-megaphone',
    'settings' => 'dashicons-admin-generic',
];
?>
<div class="wrap cw-wrap">
    <header class="cw-header">
        <div class="cw-brand">
            <span class="cw-brand__logo" aria-hidden="true">
                <?php echo ClickWarden_Admin::icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG ?>
            </span>
            <div>
                <h1 class="cw-brand__name">ClickWarden</h1>
                <p class="cw-brand__tagline"><?php esc_html_e('Click fraud protection for Google Ads', 'clickwarden'); ?></p>
            </div>
        </div>
        <span class="cw-version">v<?php echo esc_html(CLICKWARDEN_VERSION); ?></span>
    </header>

    <hr class="wp-header-end">

    <nav class="cw-tabs" aria-label="<?php esc_attr_e('ClickWarden sections', 'clickwarden'); ?>">
        <?php foreach (ClickWarden_Admin::tabs() as $slug => $label) : ?>
            <a href="<?php echo esc_url(ClickWarden_Admin::url(['tab' => $slug])); ?>"
               class="cw-tab<?php echo $slug === $tab ? ' is-active' : ''; ?>"
               <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>>
                <span class="dashicons <?php echo esc_attr($tab_icons[$slug]); ?>" aria-hidden="true"></span>
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="cw-tab-content">
        <?php include CLICKWARDEN_PLUGIN_DIR . 'templates/tab-' . $tab . '.php'; ?>
    </div>
</div>
