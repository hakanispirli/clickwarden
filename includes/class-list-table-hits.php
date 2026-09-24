<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ClickWarden_List_Table_Hits extends WP_List_Table {
    public function __construct(private ClickWarden_Database $database, private array $filters) {
        parent::__construct([
            'singular' => 'hit',
            'plural'   => 'hits',
            'ajax'     => false,
            'screen'   => get_current_screen(),
        ]);
    }

    public function get_columns(): array {
        return [
            'created_at' => __('Time', 'clickwarden'),
            'ip'         => __('IP / Risk', 'clickwarden'),
            'location'   => __('Location / Network', 'clickwarden'),
            'page'       => __('Page', 'clickwarden'),
            'source'     => __('Source', 'clickwarden'),
            'agent'      => __('Visitor', 'clickwarden'),
            'engaged_ms' => __('Engagement', 'clickwarden'),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'created_at' => ['created_at', true],
            'ip'         => ['ip', false],
            'engaged_ms' => ['engaged_ms', false],
        ];
    }

    protected function get_primary_column_name(): string {
        return 'ip';
    }

    public function prepare_items(): void {
        $per_page = ClickWarden_Admin::per_page();
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.
        $order = isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter.

        [$items, $total] = $this->database->query_hits($this->filters, $orderby, $order, $per_page, $this->get_pagenum());

        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), $this->get_primary_column_name()];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);
    }

    public function no_items(): void {
        ClickWarden_Admin::has_filters($this->filters)
            ? esc_html_e('No visits match these filters.', 'clickwarden')
            : esc_html_e('No visits recorded yet. Visits appear here as soon as the tracker script runs on your site.', 'clickwarden');
    }

    protected function column_created_at(object $item): string {
        return sprintf(
            '<span class="cw-muted" title="%s">%s</span>',
            esc_attr(ClickWarden_Admin::format_date($item->created_at, 'Y-m-d H:i:s')),
            esc_html(ClickWarden_Admin::format_date($item->created_at))
        );
    }

    protected function column_ip(object $item): string {
        $filter_url = add_query_arg(['ip' => $item->ip, 'paged' => false]);
        $out = sprintf(
            '<a class="cw-ip" href="%s"><code>%s</code></a>',
            esc_url($filter_url),
            esc_html($item->ip)
        );

        if (null !== $item->risk_score) {
            $out .= '<div class="cw-cell-sub">' . ClickWarden_Admin::risk_badge($item) . '</div>';
        }

        if ('' !== $item->ga_cid) {
            $out .= sprintf(
                '<div class="cw-cell-sub"><a class="cw-browser" href="%1$s" title="%2$s"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span>%3$s</a></div>',
                esc_url(add_query_arg(['ga' => $item->ga_cid, 'ip' => false, 'paged' => false])),
                esc_attr__('Google Analytics browser id: show every visit of this browser, from any IP', 'clickwarden'),
                esc_html(substr($item->ga_cid, 0, 10) . '…')
            );
        }

        $out .= $this->row_actions([
            'ip' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(ClickWarden_Admin::url(['tab' => 'ips', 'ip' => $item->ip])),
                esc_html__('IP details', 'clickwarden')
            ),
        ]);

        return $out;
    }

    protected function column_location(object $item): string {
        if (empty($item->country_code)) {
            return '<span class="cw-muted">' . esc_html__('Resolving…', 'clickwarden') . '</span>';
        }

        $out = sprintf(
            '<span class="cw-country">%s</span> %s',
            esc_html($item->country_code),
            esc_html(trim($item->city . ', ' . $item->country, ', '))
        );
        $out .= '<div class="cw-cell-sub cw-muted">' . esc_html($item->isp) . '</div>';

        $tags = [];
        if (!empty($item->is_hosting)) {
            $tags[] = '<span class="cw-tag cw-tag--danger">' . esc_html__('Datacenter', 'clickwarden') . '</span>';
        }
        if (!empty($item->is_proxy)) {
            $tags[] = '<span class="cw-tag cw-tag--danger">' . esc_html__('VPN/Proxy', 'clickwarden') . '</span>';
        }
        if (!empty($item->is_mobile)) {
            $tags[] = '<span class="cw-tag">' . esc_html__('Mobile network', 'clickwarden') . '</span>';
        }

        return $out . ($tags ? '<div class="cw-cell-sub">' . implode(' ', $tags) . '</div>' : '');
    }

    protected function column_page(object $item): string {
        return sprintf(
            '<a class="cw-url" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(home_url($item->url_path)),
            esc_html(mb_strimwidth($item->url_path, 0, 60, '…'))
        );
    }

    protected function column_source(object $item): string {
        $out = '';

        if ($item->click_type) {
            $out .= sprintf(
                '<span class="cw-tag cw-tag--ad" title="%s">%s</span>',
                esc_attr($item->click_type . ': ' . $item->click_id),
                esc_html__('Google Ads', 'clickwarden')
            );
            if ($item->click_dup) {
                $out .= sprintf(
                    ' <span class="cw-tag" title="%s">%s</span>',
                    esc_attr__('Same click id as an earlier visit (page reload or shared link). Google does not charge it again, so it is not counted as a new ad click.', 'clickwarden'),
                    esc_html__('Reload', 'clickwarden')
                );
            }
        }

        if ('' !== $item->gad_campaignid && '' === $item->utm_campaign) {
            /* translators: %s: Google Ads campaign id */
            $out .= '<div class="cw-cell-sub">' . esc_html(sprintf(__('Campaign ID %s', 'clickwarden'), $item->gad_campaignid)) . '</div>';
        }

        // The Google Ads cookie remembers an earlier ad click of this browser (up to 90 days).
        if ('' !== $item->gcl_aw && $item->gcl_aw !== (string) $item->click_id) {
            $out .= sprintf(
                '<div class="cw-cell-sub"><span class="cw-tag cw-tag--warn" title="%s">%s</span></div>',
                esc_attr__('The _gcl_aw cookie shows this browser clicked one of your ads before.', 'clickwarden'),
                esc_html__('Returning ad clicker', 'clickwarden')
            );
        }

        if ('' !== $item->utm_campaign || '' !== $item->utm_source) {
            $out .= '<div class="cw-cell-sub">' . esc_html(implode(' / ', array_filter([$item->utm_source, $item->utm_medium, $item->utm_campaign]))) . '</div>';
            if ('' !== $item->utm_term) {
                /* translators: %s: search keyword */
                $out .= '<div class="cw-cell-sub cw-muted">' . esc_html(sprintf(__('Keyword: %s', 'clickwarden'), $item->utm_term)) . '</div>';
            }
        }

        if ('' !== $item->referrer) {
            $host = (string) wp_parse_url($item->referrer, PHP_URL_HOST);
            $out .= sprintf('<div class="cw-cell-sub cw-muted" title="%s">%s</div>', esc_attr($item->referrer), esc_html($host ?: $item->referrer));
        }

        return $out ?: '<span class="cw-muted">' . esc_html__('Direct', 'clickwarden') . '</span>';
    }

    protected function column_agent(object $item): string {
        if ($item->is_bot) {
            $categories = ClickWarden_Bot_Detector::categories();
            $out = sprintf(
                '<span class="cw-tag cw-tag--bot" title="%s">%s</span>',
                esc_attr($categories[$item->bot_category] ?? ''),
                esc_html($item->bot_name ?: __('Bot', 'clickwarden'))
            );
        } else {
            $out = '<span class="cw-tag cw-tag--human">' . esc_html__('Human', 'clickwarden') . '</span>';
        }

        if ($item->is_webdriver) {
            $out .= ' <span class="cw-tag cw-tag--danger">' . esc_html__('WebDriver', 'clickwarden') . '</span>';
        }

        return $out . sprintf(
            '<div class="cw-cell-sub cw-muted cw-ua" title="%1$s">%2$s</div>',
            esc_attr($item->user_agent),
            esc_html(mb_strimwidth($item->user_agent, 0, 70, '…'))
        );
    }

    protected function column_engaged_ms(object $item): string {
        $ms = null === $item->engaged_ms ? null : (int) $item->engaged_ms;
        $out = '<strong>' . esc_html(ClickWarden_Admin::format_duration($ms)) . '</strong>';

        $parts = [];
        $parts[] = $item->interacted
            ? esc_html__('Interacted', 'clickwarden')
            : '<span class="cw-text-danger">' . esc_html__('No interaction', 'clickwarden') . '</span>';
        if ((int) $item->scroll_pct > 0) {
            /* translators: %d: scroll depth percentage */
            $parts[] = esc_html(sprintf(__('%d%% scrolled', 'clickwarden'), (int) $item->scroll_pct));
        }

        return $out . '<div class="cw-cell-sub cw-muted">' . implode(' · ', $parts) . '</div>';
    }
}
