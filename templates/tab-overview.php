<?php
/**
 * @var array $data
 */

if (!defined('ABSPATH')) {
    exit;
}

// Included from ClickWarden_Admin::render_page(), so these variables are local to that method.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$o = $data['overview'];
$week = $o['week'];
$pct = static fn($part, $total): string => $total > 0 ? number_format_i18n(100 * $part / $total, 1) . '%' : '—';

$cards = [
    [
        'label' => __('Ad clicks today', 'clickwarden'),
        'value' => number_format_i18n((int) $o['today']->ad_clicks),
        /* translators: %s: number of suspicious clicks */
        'meta'  => sprintf(__('%s from suspicious IPs', 'clickwarden'), number_format_i18n((int) $o['today']->suspicious_clicks)),
        'tone'  => (int) $o['today']->suspicious_clicks > 0 ? 'danger' : 'neutral',
        'link'  => ClickWarden_Admin::url(['tab' => 'hits', 'ad' => 1, 'date_from' => wp_date('Y-m-d')]),
    ],
    [
        'label' => __('Suspicious IPs', 'clickwarden'),
        'value' => number_format_i18n((int) $o['ips']->suspicious_ips),
        /* translators: %s: number of IPs on the watch list */
        'meta'  => sprintf(__('%s more on the watch list', 'clickwarden'), number_format_i18n((int) $o['ips']->watch_ips)),
        'tone'  => (int) $o['ips']->suspicious_ips > 0 ? 'danger' : 'good',
        'link'  => ClickWarden_Admin::url(['tab' => 'ips', 'level' => 'suspicious']),
    ],
    [
        'label' => __('Suspicious click rate (7 days)', 'clickwarden'),
        'value' => $pct((int) $week->suspicious_clicks, (int) $week->ad_clicks),
        /* translators: 1: suspicious clicks, 2: total ad clicks */
        'meta'  => sprintf(__('%1$s of %2$s ad clicks', 'clickwarden'), number_format_i18n((int) $week->suspicious_clicks), number_format_i18n((int) $week->ad_clicks)),
        'tone'  => 'warning',
        'link'  => ClickWarden_Admin::url(['tab' => 'hits', 'ad' => 1, 'risk' => 'suspicious']),
    ],
    [
        'label' => __('Datacenter / VPN clicks (7 days)', 'clickwarden'),
        'value' => $pct((int) $week->network_clicks, (int) $week->ad_clicks),
        /* translators: %s: share of bot traffic */
        'meta'  => sprintf(__('Bot share of all traffic: %s', 'clickwarden'), $pct((int) $week->bot_hits, (int) $week->hits)),
        'tone'  => 'neutral',
        'link'  => ClickWarden_Admin::url(['tab' => 'ips', 'network' => 'hosting', 'min_clicks' => 1]),
    ],
];

$series = $o['series'];
$max = max(1, ...array_values(array_column($series, 'ad_clicks')));
$chart_w = 700;
$chart_h = 200;
$pad_b = 24;
$slot = $chart_w / count($series);
$bar_w = $slot * 0.6;

$setup = $data['setup'];
$setup_done = count(array_filter(array_column($setup, 'done')));
?>

<?php if ($setup_done < count($setup)) : ?>
    <section class="cw-panel cw-setup" aria-labelledby="cw-setup-title">
        <header class="cw-panel__head">
            <div>
                <h2 id="cw-setup-title"><?php esc_html_e('Get started', 'clickwarden'); ?></h2>
                <p class="cw-muted">
                    <?php
                    /* translators: 1: completed steps, 2: total steps */
                    echo esc_html(sprintf(__('%1$d of %2$d steps completed', 'clickwarden'), $setup_done, count($setup)));
                    ?>
                </p>
            </div>
            <progress class="cw-progress" max="<?php echo (int) count($setup); ?>" value="<?php echo (int) $setup_done; ?>"></progress>
        </header>
        <ol class="cw-steps-list">
            <?php foreach ($setup as $step) : ?>
                <li class="cw-step<?php echo $step['done'] ? ' is-done' : ''; ?>">
                    <span class="cw-step__icon dashicons <?php echo $step['done'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
                    <div class="cw-step__body">
                        <a href="<?php echo esc_url($step['url']); ?>" class="cw-step__title">
                            <?php echo esc_html($step['title']); ?>
                            <span class="screen-reader-text"><?php echo $step['done'] ? esc_html__('(done)', 'clickwarden') : esc_html__('(to do)', 'clickwarden'); ?></span>
                        </a>
                        <span class="cw-step__text"><?php echo esc_html($step['text']); ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<div class="cw-cards">
    <?php foreach ($cards as $card) : ?>
        <a class="cw-card cw-card--<?php echo esc_attr($card['tone']); ?>" href="<?php echo esc_url($card['link']); ?>">
            <span class="cw-card__label"><?php echo esc_html($card['label']); ?></span>
            <span class="cw-card__value"><?php echo esc_html($card['value']); ?></span>
            <span class="cw-card__meta"><?php echo esc_html($card['meta']); ?></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="cw-grid">
    <section class="cw-panel cw-panel--wide">
        <header class="cw-panel__head">
            <h2><?php esc_html_e('Ad clicks, last 14 days', 'clickwarden'); ?></h2>
            <ul class="cw-legend">
                <li><span class="cw-legend__swatch cw-legend__swatch--clean"></span><?php esc_html_e('Normal', 'clickwarden'); ?></li>
                <li><span class="cw-legend__swatch cw-legend__swatch--suspicious"></span><?php esc_html_e('Suspicious', 'clickwarden'); ?></li>
            </ul>
        </header>
        <svg class="cw-chart" aria-hidden="true" focusable="false" viewBox="0 0 <?php echo (int) $chart_w; ?> <?php echo (int) ($chart_h + $pad_b); ?>">
            <?php foreach ([0.25, 0.5, 0.75, 1] as $line) : ?>
                <line class="cw-chart__grid" x1="0" x2="<?php echo (int) $chart_w; ?>"
                      y1="<?php echo esc_attr(round($chart_h - $chart_h * $line, 1)); ?>"
                      y2="<?php echo esc_attr(round($chart_h - $chart_h * $line, 1)); ?>"></line>
            <?php endforeach; ?>
            <?php
            $i = 0;
            foreach ($series as $day => $values) :
                $x = $i * $slot + ($slot - $bar_w) / 2;
                $total_h = $chart_h * $values['ad_clicks'] / $max;
                $susp_h = $chart_h * $values['suspicious_clicks'] / $max;
                $label = wp_date('j M', strtotime($day . ' 12:00:00'));
                ?>
                <g>
                    <title><?php
                        /* translators: 1: date, 2: ad clicks, 3: suspicious clicks */
                        echo esc_html(sprintf(__('%1$s: %2$d ad clicks, %3$d suspicious', 'clickwarden'), $label, $values['ad_clicks'], $values['suspicious_clicks']));
                    ?></title>
                    <rect class="cw-chart__bar" x="<?php echo esc_attr(round($x, 1)); ?>" y="<?php echo esc_attr(round($chart_h - $total_h, 1)); ?>"
                          width="<?php echo esc_attr(round($bar_w, 1)); ?>" height="<?php echo esc_attr(round($total_h, 1)); ?>" rx="3"></rect>
                    <?php if ($susp_h > 0) : ?>
                        <rect class="cw-chart__bar cw-chart__bar--suspicious" x="<?php echo esc_attr(round($x, 1)); ?>" y="<?php echo esc_attr(round($chart_h - $susp_h, 1)); ?>"
                              width="<?php echo esc_attr(round($bar_w, 1)); ?>" height="<?php echo esc_attr(round($susp_h, 1)); ?>" rx="3"></rect>
                    <?php endif; ?>
                    <?php if ($values['ad_clicks'] > 0) : ?>
                        <text class="cw-chart__value" x="<?php echo esc_attr(round($x + $bar_w / 2, 1)); ?>" y="<?php echo esc_attr(round(max(12, $chart_h - $total_h - 4), 1)); ?>"><?php echo (int) $values['ad_clicks']; ?></text>
                    <?php endif; ?>
                    <text class="cw-chart__label" x="<?php echo esc_attr(round($x + $bar_w / 2, 1)); ?>" y="<?php echo (int) ($chart_h + 17); ?>"><?php echo esc_html($label); ?></text>
                </g>
                <?php
                $i++;
            endforeach;
            ?>
        </svg>
        <table class="screen-reader-text">
            <caption><?php esc_html_e('Ad clicks, last 14 days', 'clickwarden'); ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Day', 'clickwarden'); ?></th>
                    <th scope="col"><?php esc_html_e('Ad clicks', 'clickwarden'); ?></th>
                    <th scope="col"><?php esc_html_e('Suspicious', 'clickwarden'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($series as $day => $values) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html(wp_date(get_option('date_format'), strtotime($day . ' 12:00:00'))); ?></th>
                        <td><?php echo (int) $values['ad_clicks']; ?></td>
                        <td><?php echo (int) $values['suspicious_clicks']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="cw-panel">
        <header class="cw-panel__head">
            <h2><?php esc_html_e('Riskiest IP addresses', 'clickwarden'); ?></h2>
            <a href="<?php echo esc_url(ClickWarden_Admin::url(['tab' => 'ips'])); ?>"><?php esc_html_e('View all', 'clickwarden'); ?></a>
        </header>
        <?php if (empty($o['top_ips'])) : ?>
            <p class="cw-empty"><?php esc_html_e('No ad clicks recorded yet.', 'clickwarden'); ?></p>
        <?php else : ?>
            <table class="cw-mini-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('IP Address', 'clickwarden'); ?></th>
                        <th><?php esc_html_e('Risk', 'clickwarden'); ?></th>
                        <th class="cw-num"><?php esc_html_e('Ad clicks', 'clickwarden'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($o['top_ips'] as $ip) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(ClickWarden_Admin::url(['tab' => 'hits', 'ip' => $ip->ip])); ?>"><code><?php echo esc_html($ip->ip); ?></code></a>
                                <div class="cw-cell-sub cw-muted"><?php echo esc_html(trim($ip->country_code . ' · ' . $ip->isp, ' ·')); ?></div>
                            </td>
                            <td><?php echo ClickWarden_Admin::risk_badge($ip); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper ?></td>
                            <td class="cw-num"><strong><?php echo esc_html(number_format_i18n((int) $ip->ad_clicks)); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="cw-panel">
        <header class="cw-panel__head">
            <h2><?php esc_html_e('Campaigns (7 days)', 'clickwarden'); ?></h2>
        </header>
        <?php if (empty($o['campaigns'])) : ?>
            <p class="cw-empty"><?php esc_html_e('No ad clicks recorded yet.', 'clickwarden'); ?></p>
        <?php else : ?>
            <table class="cw-mini-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Campaign', 'clickwarden'); ?></th>
                        <th class="cw-num"><?php esc_html_e('Clicks', 'clickwarden'); ?></th>
                        <th class="cw-num"><?php esc_html_e('Suspicious', 'clickwarden'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($o['campaigns'] as $row) : ?>
                        <tr>
                            <td>
                                <?php if ('' !== $row->campaign) : ?>
                                    <a href="<?php echo esc_url(ClickWarden_Admin::url(['tab' => 'hits', 'ad' => 1, 'campaign' => $row->campaign])); ?>">
                                        <?php
                                        echo esc_html(str_starts_with($row->campaign, '#')
                                            /* translators: %s: Google Ads campaign id */
                                            ? sprintf(__('Campaign ID %s', 'clickwarden'), substr($row->campaign, 1))
                                            : $row->campaign);
                                        ?>
                                    </a>
                                <?php else : ?>
                                    <span class="cw-muted"><?php esc_html_e('(unknown campaign)', 'clickwarden'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="cw-num"><?php echo esc_html(number_format_i18n((int) $row->ad_clicks)); ?></td>
                            <td class="cw-num <?php echo (int) $row->suspicious_clicks > 0 ? 'cw-text-danger' : ''; ?>">
                                <?php echo esc_html(number_format_i18n((int) $row->suspicious_clicks)); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<div class="cw-callout">
    <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
    <p>
        <?php esc_html_e('Tip: Google Ads adds the campaign ID to your URLs automatically. To also see which keywords attract suspicious clicks, add utm_term={keyword} to the Final URL suffix (Google Ads → Settings → Tracking). Compare the ad clicks here with the clicks in Google Ads: bots that do not run JavaScript never reach this report.', 'clickwarden'); ?>
    </p>
</div>
