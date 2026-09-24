<?php
/**
 * @var array $data
 */

if (!defined('ABSPATH')) {
    exit;
}

// Included from ClickWarden_Admin::render_page(), so these variables are local to that method.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$status = $data['status'];
$stale = empty($status['time']) || $status['time'] < time() - 3 * HOUR_IN_SECONDS;
?>

<div class="cw-cards">
    <div class="cw-card cw-card--<?php echo $stale ? 'warning' : 'good'; ?>">
        <span class="cw-card__label"><?php esc_html_e('Last sync', 'clickwarden'); ?></span>
        <span class="cw-card__value cw-card__value--small">
            <?php
            echo empty($status['time'])
                ? esc_html__('Never', 'clickwarden')
                /* translators: %s: human readable time difference */
                : esc_html(sprintf(__('%s ago', 'clickwarden'), human_time_diff($status['time'])));
            ?>
        </span>
        <span class="cw-card__meta">
            <?php
            echo empty($status['time'])
                ? esc_html__('The Google Ads script has not reported yet.', 'clickwarden')
                /* translators: 1: added IP count, 2: removed IP count */
                : esc_html(sprintf(__('%1$d added, %2$d removed', 'clickwarden'), (int) $status['added'], (int) $status['removed']));
            ?>
        </span>
    </div>
    <div class="cw-card cw-card--neutral">
        <span class="cw-card__label"><?php esc_html_e('Blocked in Google Ads', 'clickwarden'); ?></span>
        <span class="cw-card__value"><?php echo esc_html(number_format_i18n((int) $data['excluded'])); ?></span>
        <span class="cw-card__meta">
            <?php
            /* translators: %d: number of IPs waiting on the exclusion list */
            echo esc_html(sprintf(__('%d IPs on the exclusion list', 'clickwarden'), (int) $data['queued']));
            ?>
        </span>
    </div>
</div>

<?php if (!empty($status['missing']) || !empty($status['errors'])) : ?>
    <div class="notice notice-error inline">
        <?php if (!empty($status['missing'])) : ?>
            <p>
                <strong><?php esc_html_e('Campaigns not found in Google Ads:', 'clickwarden'); ?></strong>
                <?php echo esc_html(implode(', ', $status['missing'])); ?>
                — <?php esc_html_e('check that the names below match exactly.', 'clickwarden'); ?>
            </p>
        <?php endif; ?>
        <?php foreach ((array) ($status['errors'] ?? []) as $error) : ?>
            <p><code><?php echo esc_html($error); ?></code></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="cw-settings">
    <div>
        <section class="cw-panel">
            <header class="cw-panel__head">
                <div>
                    <h2><?php esc_html_e('Google Ads script', 'clickwarden'); ?></h2>
                    <p class="cw-muted"><?php esc_html_e('This script already contains your site address and secret access key. Do not share it.', 'clickwarden'); ?></p>
                </div>
                <button type="button" class="button button-primary" data-cw-copy-target="#cw-ads-script">
                    <span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
                    <?php esc_html_e('Copy script', 'clickwarden'); ?>
                </button>
            </header>
            <textarea id="cw-ads-script" class="cw-exclusions cw-script" rows="14" readonly><?php echo esc_textarea($data['script']); ?></textarea>

            <h3><?php esc_html_e('Setup', 'clickwarden'); ?></h3>
            <ol class="cw-steps">
                <li><?php esc_html_e('Google Ads → Tools → Bulk actions → Scripts → click the blue + button → "New script".', 'clickwarden'); ?></li>
                <li><?php esc_html_e('Delete the sample code, paste this script and give it a name, e.g. "ClickWarden IP sync".', 'clickwarden'); ?></li>
                <li><?php esc_html_e('Click "Authorize" and allow access with your Google account.', 'clickwarden'); ?></li>
                <li><?php esc_html_e('Click "Preview": the log shows what would be added. Preview makes no changes.', 'clickwarden'); ?></li>
                <li><?php esc_html_e('Save, set "Frequency" to "Hourly". The first real run appears above as "Last sync".', 'clickwarden'); ?></li>
            </ol>
        </section>
    </div>

    <aside class="cw-settings__side">
        <form method="post" action="options.php" class="cw-panel">
            <?php settings_fields(ClickWarden_Settings::GROUP); ?>
            <h2><?php esc_html_e('Campaigns', 'clickwarden'); ?></h2>
            <label for="cw-ads-campaigns" class="cw-muted"><?php esc_html_e('Exact campaign names, one per line. IP exclusions apply to the whole campaign, including all its ad groups.', 'clickwarden'); ?></label>
            <textarea id="cw-ads-campaigns" class="large-text" rows="4" name="<?php echo esc_attr(ClickWarden_Settings::OPTION . '[ads_campaigns]'); ?>"><?php echo esc_textarea($data['settings']['ads_campaigns']); ?></textarea>
            <?php if (!empty($status['campaigns'])) : ?>
                <p class="cw-muted">
                    <?php esc_html_e('Found in last sync:', 'clickwarden'); ?>
                    <?php echo esc_html(implode(', ', $status['campaigns'])); ?>
                </p>
            <?php endif; ?>
            <?php submit_button(__('Save campaigns', 'clickwarden'), 'secondary', 'submit', false); ?>
        </form>

        <section class="cw-panel">
            <h2><?php esc_html_e('How it works', 'clickwarden'); ?></h2>
            <ul class="cw-reasons">
                <li><?php esc_html_e('Every hour the script fetches the exclusion list from this site and adds new suspicious IPs to the campaigns, riskiest first.', 'clickwarden'); ?></li>
                <li><?php esc_html_e('IPs you whitelist are removed from Google Ads on the next run.', 'clickwarden'); ?></li>
                <li><?php esc_html_e('IPs you added by hand in Google Ads are never touched.', 'clickwarden'); ?></li>
                <li><?php esc_html_e('The 500 IP per campaign limit of Google Ads is respected.', 'clickwarden'); ?></li>
            </ul>
        </section>

        <section class="cw-panel cw-panel--danger">
            <h2><?php esc_html_e('Access key', 'clickwarden'); ?></h2>
            <p class="cw-muted"><?php esc_html_e('If the script was shared by mistake, create a new key. The old script stops working until you paste the new one.', 'clickwarden'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  data-cw-confirm="<?php esc_attr_e('Create a new access key? The script in Google Ads must be replaced afterwards.', 'clickwarden'); ?>">
                <?php wp_nonce_field('clickwarden_ads_token'); ?>
                <input type="hidden" name="action" value="clickwarden_ads_token">
                <button type="submit" class="button cw-button-danger"><?php esc_html_e('Create new key', 'clickwarden'); ?></button>
            </form>
        </section>
    </aside>
</div>
