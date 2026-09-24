<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ClickWarden_List_Table_IPs extends WP_List_Table {
    public function __construct(private ClickWarden_Database $database, private array $filters) {
        parent::__construct([
            'singular' => 'ip',
            'plural'   => 'ips',
            'ajax'     => false,
            'screen'   => get_current_screen(),
        ]);
    }

    public function get_columns(): array {
        return [
            'cb'         => '<input type="checkbox" />',
            'ip'         => __('IP Address', 'clickwarden'),
            'risk_score' => __('Risk', 'clickwarden'),
            'country_code' => __('Location / Network', 'clickwarden'),
            'ad_clicks'  => __('Ad clicks', 'clickwarden'),
            'hits'       => __('Visits', 'clickwarden'),
            'last_seen'  => __('Last seen', 'clickwarden'),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'ip'           => ['ip', false],
            'risk_score'   => ['risk_score', true],
            'country_code' => ['country_code', false],
            'ad_clicks'    => ['ad_clicks', true],
            'hits'         => ['hits', true],
            'last_seen'    => ['last_seen', true],
        ];
    }

    protected function get_primary_column_name(): string {
        return 'ip';
    }

    protected function get_bulk_actions(): array {
        return [
            'flag'      => __('Mark as suspicious', 'clickwarden'),
            'whitelist' => __('Whitelist', 'clickwarden'),
            'reset'     => __('Reset to automatic', 'clickwarden'),
        ];
    }

    public function prepare_items(): void {
        $per_page = ClickWarden_Admin::per_page();
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'risk_score'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.

        [$items, $total] = $this->database->query_ips($this->filters, $orderby, $order, $per_page, $this->get_pagenum());

        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), $this->get_primary_column_name()];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }

    public function no_items(): void {
        esc_html_e('No IP addresses match these filters.', 'clickwarden');
    }

    protected function column_cb($item): string {
        return sprintf(
            '<label class="screen-reader-text" for="cw-ip-%1$s">%2$s</label><input type="checkbox" id="cw-ip-%1$s" name="ips[]" value="%3$s" />',
            esc_attr(md5($item->ip)),
            /* translators: %s: IP address */
            esc_html(sprintf(__('Select %s', 'clickwarden'), $item->ip)),
            esc_attr($item->ip)
        );
    }

    protected function column_ip(object $item): string {
        $out = sprintf(
            '<code class="cw-ip">%1$s</code> <button type="button" class="cw-copy button-link" data-cw-copy="%1$s" aria-label="%2$s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>',
            esc_html($item->ip),
            esc_attr__('Copy IP address', 'clickwarden')
        );

        if ('whitelisted' === $item->status) {
            $out .= ' <span class="cw-tag cw-tag--human">' . esc_html__('Whitelisted', 'clickwarden') . '</span>';
        } elseif ('flagged' === $item->status) {
            $out .= ' <span class="cw-tag cw-tag--danger">' . esc_html__('Flagged manually', 'clickwarden') . '</span>';
        }

        if (!empty($item->ads_excluded)) {
            $out .= ' <span class="cw-tag cw-tag--ad">' . esc_html__('Blocked in Google Ads', 'clickwarden') . '</span>';
        }

        $actions = [
            'visits' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(ClickWarden_Admin::url(['tab' => 'hits', 'ip' => $item->ip])),
                esc_html__('View visits', 'clickwarden')
            ),
        ];
        if ('flagged' !== $item->status) {
            $actions['flag'] = sprintf('<a href="%s">%s</a>', esc_url(ClickWarden_Admin::ip_action_url($item->ip, 'flag')), esc_html__('Mark suspicious', 'clickwarden'));
        }
        if ('whitelisted' !== $item->status) {
            $actions['whitelist'] = sprintf('<a href="%s">%s</a>', esc_url(ClickWarden_Admin::ip_action_url($item->ip, 'whitelist')), esc_html__('Whitelist', 'clickwarden'));
        }
        if ('none' !== $item->status) {
            $actions['reset'] = sprintf('<a href="%s">%s</a>', esc_url(ClickWarden_Admin::ip_action_url($item->ip, 'reset')), esc_html__('Reset', 'clickwarden'));
        }

        return $out . $this->row_actions($actions);
    }

    protected function column_risk_score(object $item): string {
        $out = ClickWarden_Admin::risk_badge($item);
        $reasons = ClickWarden_Risk_Scorer::describe_reasons((string) $item->risk_reasons);
        if ($reasons) {
            $out .= '<ul class="cw-reasons">';
            foreach ($reasons as $reason) {
                $out .= '<li>' . esc_html($reason) . '</li>';
            }
            $out .= '</ul>';
        }
        return $out;
    }

    protected function column_country_code(object $item): string {
        if ('done' !== $item->geo_status) {
            $label = 'failed' === $item->geo_status ? __('Lookup failed, retrying', 'clickwarden') : __('Resolving…', 'clickwarden');
            return '<span class="cw-muted">' . esc_html($label) . '</span>';
        }

        $out = sprintf(
            '<span class="cw-country">%s</span> %s',
            esc_html($item->country_code ?: '--'),
            esc_html(trim($item->city . ', ' . $item->country, ', '))
        );
        $out .= sprintf('<div class="cw-cell-sub cw-muted" title="%s">%s</div>', esc_attr($item->org), esc_html($item->isp));
        if ($item->asn) {
            $out .= '<div class="cw-cell-sub cw-muted">' . esc_html($item->asn) . '</div>';
        }

        $tags = [];
        if ($item->is_hosting) {
            $tags[] = '<span class="cw-tag cw-tag--danger">' . esc_html__('Datacenter', 'clickwarden') . '</span>';
        }
        if ($item->is_proxy) {
            $tags[] = '<span class="cw-tag cw-tag--danger">' . esc_html__('VPN/Proxy', 'clickwarden') . '</span>';
        }
        if ($item->is_mobile) {
            $tags[] = '<span class="cw-tag">' . esc_html__('Mobile network', 'clickwarden') . '</span>';
        }

        return $out . ($tags ? '<div class="cw-cell-sub">' . implode(' ', $tags) . '</div>' : '');
    }

    protected function column_ad_clicks(object $item): string {
        $class = (int) $item->ad_clicks >= (int) ClickWarden_Settings::get('click_threshold') ? ' cw-text-danger' : '';
        return sprintf('<strong class="cw-num%s">%s</strong>', $class, esc_html(number_format_i18n((int) $item->ad_clicks)));
    }

    protected function column_hits(object $item): string {
        return '<span class="cw-num">' . esc_html(number_format_i18n((int) $item->hits)) . '</span>';
    }

    protected function column_last_seen(object $item): string {
        return sprintf(
            '%s<div class="cw-cell-sub cw-muted">%s</div>',
            esc_html(ClickWarden_Admin::format_date($item->last_seen)),
            /* translators: %s: date */
            esc_html(sprintf(__('First: %s', 'clickwarden'), ClickWarden_Admin::format_date($item->first_seen)))
        );
    }
}
