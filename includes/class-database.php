<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * All dates are stored in UTC (Y-m-d H:i:s).
 *
 * A hit is an "ad click" when it carries a Google click id (gclid/gbraid/wbraid)
 * that was not seen before (click_dup = 0). Reloading an ad landing page keeps
 * the same click id in the URL; Google does not charge it twice, so neither do we.
 *
 * The plugin owns its two tables, so reads go straight to them: caching these
 * constantly changing counters would only return stale numbers.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class ClickWarden_Database {
    public readonly string $hits;
    public readonly string $ips;

    /** Internal SQL fragment (no user input): a first-time, non-AdsBot ad click. */
    private const AD_CLICK_SQL = "h.click_type IS NOT NULL AND h.click_dup = 0 AND h.bot_category <> 'ads'";

    /** Internal SQL fragment: campaign label, utm_campaign or the id Google Ads appends. */
    private const CAMPAIGN_SQL = "CASE WHEN h.utm_campaign <> '' THEN h.utm_campaign
        WHEN h.gad_campaignid <> '' THEN CONCAT('#', h.gad_campaignid) ELSE '' END";

    public function __construct() {
        global $wpdb;
        $this->hits = $wpdb->prefix . 'clickwarden_hits';
        $this->ips = $wpdb->prefix . 'clickwarden_ips';
    }

    public static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public static function ago(int $seconds): string {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /* ------------------------------------------------------------------
     * Schema
     * ---------------------------------------------------------------- */

    public function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $hits = "CREATE TABLE {$this->hits} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            hit_uid char(36) NOT NULL,
            ip varchar(45) NOT NULL,
            url_path varchar(255) NOT NULL DEFAULT '',
            referrer varchar(500) NOT NULL DEFAULT '',
            user_agent varchar(500) NOT NULL DEFAULT '',
            click_type varchar(10) DEFAULT NULL,
            click_id varchar(255) DEFAULT NULL,
            click_dup tinyint(1) unsigned NOT NULL DEFAULT 0,
            gad_source varchar(10) NOT NULL DEFAULT '',
            gad_campaignid varchar(32) NOT NULL DEFAULT '',
            utm_source varchar(100) NOT NULL DEFAULT '',
            utm_medium varchar(100) NOT NULL DEFAULT '',
            utm_campaign varchar(100) NOT NULL DEFAULT '',
            utm_term varchar(100) NOT NULL DEFAULT '',
            ga_cid varchar(64) NOT NULL DEFAULT '',
            gcl_au varchar(64) NOT NULL DEFAULT '',
            gcl_aw varchar(255) NOT NULL DEFAULT '',
            gcl_gb varchar(255) NOT NULL DEFAULT '',
            gcl_gs varchar(128) NOT NULL DEFAULT '',
            is_bot tinyint(1) unsigned NOT NULL DEFAULT 0,
            bot_category varchar(20) NOT NULL DEFAULT '',
            bot_name varchar(50) NOT NULL DEFAULT '',
            is_webdriver tinyint(1) unsigned NOT NULL DEFAULT 0,
            engaged_ms int(10) unsigned DEFAULT NULL,
            interacted tinyint(1) unsigned NOT NULL DEFAULT 0,
            scroll_pct tinyint(3) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY hit_uid (hit_uid),
            KEY ip_created (ip,created_at),
            KEY created_at (created_at),
            KEY click_created (click_type,created_at),
            KEY click_id (click_id(40)),
            KEY ga_cid (ga_cid),
            KEY utm_campaign (utm_campaign)
        ) $charset_collate;";

        $ips = "CREATE TABLE {$this->ips} (
            ip varchar(45) NOT NULL,
            country_code char(2) NOT NULL DEFAULT '',
            country varchar(100) NOT NULL DEFAULT '',
            city varchar(100) NOT NULL DEFAULT '',
            isp varchar(150) NOT NULL DEFAULT '',
            org varchar(150) NOT NULL DEFAULT '',
            asn varchar(150) NOT NULL DEFAULT '',
            is_proxy tinyint(1) unsigned NOT NULL DEFAULT 0,
            is_hosting tinyint(1) unsigned NOT NULL DEFAULT 0,
            is_mobile tinyint(1) unsigned NOT NULL DEFAULT 0,
            geo_status varchar(10) NOT NULL DEFAULT 'pending',
            geo_updated_at datetime DEFAULT NULL,
            hits int(10) unsigned NOT NULL DEFAULT 0,
            ad_clicks int(10) unsigned NOT NULL DEFAULT 0,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            rate_limited_at datetime DEFAULT NULL,
            risk_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            risk_reasons varchar(255) NOT NULL DEFAULT '',
            status varchar(12) NOT NULL DEFAULT 'none',
            note varchar(255) NOT NULL DEFAULT '',
            ads_excluded tinyint(1) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (ip),
            KEY risk_score (risk_score),
            KEY last_seen (last_seen),
            KEY country_code (country_code),
            KEY status (status),
            KEY geo_status (geo_status),
            KEY ads_excluded (ads_excluded)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($hits);
        dbDelta($ips);
    }

    private function table_exists(string $table): bool {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    /**
     * One-time import from the plugin's previous name ("Visitor Tracker" 2.x by Webmarka).
     * Only runs when that plugin's own version option exists and its tables carry its
     * columns, so tables of unrelated plugins with similar names are never touched.
     */
    public function migrate_from_visitor_tracker(): bool {
        global $wpdb;
        if ($this->table_exists($this->hits) || !get_option('vt_db_version')) {
            return false;
        }

        $old_hits = $wpdb->prefix . 'vt_hits';
        $old_ips = $wpdb->prefix . 'vt_ips';
        if (!$this->table_exists($old_hits) || !$this->table_exists($old_ips)) {
            return false;
        }

        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $old_hits));
        if (!in_array('hit_uid', $columns, true) || !in_array('bot_category', $columns, true)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time rename of this plugin's own tables.
        $wpdb->query($wpdb->prepare('RENAME TABLE %i TO %i, %i TO %i', $old_hits, $this->hits, $old_ips, $this->ips));

        $settings = get_option('vt_settings');
        if (is_array($settings) && false === get_option(ClickWarden_Settings::OPTION)) {
            // The previous version always used ipapi.is with an ip-api.com fallback.
            $settings['geo_enabled'] = 1;
            $settings['ipapi_fallback'] = 1;
            add_option(ClickWarden_Settings::OPTION, $settings);
        }

        foreach (['vt_settings', 'vt_db_version', 'vt_ads_token', 'vt_ads_sync'] as $option) {
            delete_option($option);
        }
        foreach (['vt_geo_queue', 'vt_rescore', 'vt_purge'] as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        return true;
    }

    /**
     * After importing old data: page reloads carrying the same click id are not new ad clicks.
     */
    public function recount_ad_clicks(): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE %i h
             JOIN (SELECT click_id, MIN(id) AS first_id FROM %i WHERE click_id IS NOT NULL GROUP BY click_id) f
               ON f.click_id = h.click_id
             SET h.click_dup = (h.id <> f.first_id)',
            $this->hits,
            $this->hits
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE %i i SET ad_clicks = (
                SELECT COUNT(*) FROM %i h
                WHERE h.ip = i.ip AND h.click_type IS NOT NULL AND h.click_dup = 0 AND h.bot_category <> 'ads'
             )",
            $this->ips,
            $this->hits
        ));
    }

    /* ------------------------------------------------------------------
     * Tracking writes
     * ---------------------------------------------------------------- */

    public function insert_hit(array $data): int {
        global $wpdb;
        $inserted = $wpdb->insert($this->hits, $data);
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Atomic per-IP counter update, safe under concurrent requests.
     */
    public function touch_ip(string $ip, bool $is_ad_click, string $now): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO %i (ip, geo_status, hits, ad_clicks, first_seen, last_seen)
             VALUES (%s, 'pending', 1, %d, %s, %s)
             ON DUPLICATE KEY UPDATE hits = hits + 1, ad_clicks = ad_clicks + %d, last_seen = %s",
            $this->ips,
            $ip,
            (int) $is_ad_click,
            $now,
            $now,
            (int) $is_ad_click,
            $now
        ));
    }

    public function hit_uid_exists(string $uid): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM %i WHERE hit_uid = %s LIMIT 1',
            $this->hits,
            $uid
        ));
    }

    public function click_id_exists(string $click_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM %i WHERE click_id = %s LIMIT 1',
            $this->hits,
            $click_id
        ));
    }

    public function count_recent_hits(string $ip, int $seconds): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE ip = %s AND created_at >= %s',
            $this->hits,
            $ip,
            self::ago($seconds)
        ));
    }

    public function mark_rate_limited(string $ip): void {
        global $wpdb;
        $wpdb->update($this->ips, ['rate_limited_at' => self::now()], ['ip' => $ip]);
    }

    /**
     * Merges engagement data (keeps the highest values, a page can report several times)
     * and fills in Google cookie values that only exist once the Google tag has loaded.
     */
    public function update_engagement(string $uid, string $ip, array $data, string $since): ?object {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE %i
             SET engaged_ms = GREATEST(COALESCE(engaged_ms, 0), %d),
                 interacted = GREATEST(interacted, %d),
                 scroll_pct = GREATEST(scroll_pct, %d),
                 ga_cid = IF(ga_cid = '', %s, ga_cid),
                 gcl_au = IF(gcl_au = '', %s, gcl_au),
                 gcl_aw = IF(gcl_aw = '', %s, gcl_aw),
                 gcl_gb = IF(gcl_gb = '', %s, gcl_gb),
                 gcl_gs = IF(gcl_gs = '', %s, gcl_gs)
             WHERE hit_uid = %s AND ip = %s AND created_at >= %s",
            $this->hits,
            $data['engaged_ms'],
            (int) $data['interacted'],
            $data['scroll_pct'],
            $data['ga_cid'],
            $data['gcl_au'],
            $data['gcl_aw'],
            $data['gcl_gb'],
            $data['gcl_gs'],
            $uid,
            $ip,
            $since
        ));

        if (!$updated) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT id, click_type, ga_cid FROM %i WHERE hit_uid = %s',
            $this->hits,
            $uid
        ));
    }

    /* ------------------------------------------------------------------
     * IP records
     * ---------------------------------------------------------------- */

    public function get_ip(string $ip): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ip = %s', $this->ips, $ip));
    }

    public function update_ip(string $ip, array $data): void {
        global $wpdb;
        $wpdb->update($this->ips, $data, ['ip' => $ip]);
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated list of %s.
    public function set_ip_status(array $ips, string $status): int {
        global $wpdb;
        if (!$ips || !in_array($status, ['none', 'whitelisted', 'flagged'], true)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ips), '%s'));
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = %s WHERE ip IN ($placeholders)",
            $this->ips,
            $status,
            ...$ips
        ));
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

    public function get_geo_queue(int $limit): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT ip FROM %i
             WHERE geo_status = 'pending'
                OR (geo_status = 'failed' AND (geo_updated_at IS NULL OR geo_updated_at < %s))
                OR (geo_status = 'done' AND geo_updated_at < %s AND last_seen >= %s)
             ORDER BY (ad_clicks > 0) DESC, (geo_status = 'pending') DESC, last_seen DESC
             LIMIT %d",
            $this->ips,
            self::ago(HOUR_IN_SECONDS),
            self::ago(30 * DAY_IN_SECONDS),
            self::ago(DAY_IN_SECONDS),
            $limit
        ));
    }

    public function count_geo_pending(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE geo_status = 'pending'", $this->ips));
    }

    public function get_active_ips(string $since, int $limit): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            'SELECT ip FROM %i WHERE last_seen >= %s ORDER BY last_seen DESC LIMIT %d',
            $this->ips,
            $since,
            $limit
        ));
    }

    /**
     * Other IPs the same browser (GA client id) used inside the window.
     */
    public function get_ips_for_browser(string $ga_cid, string $since, int $limit = 20): array {
        global $wpdb;
        if ('' === $ga_cid) {
            return [];
        }
        return $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT ip FROM %i WHERE ga_cid = %s AND created_at >= %s LIMIT %d',
            $this->hits,
            $ga_cid,
            $since,
            $limit
        ));
    }

    /**
     * Aggregated behaviour of one IP, and of the browsers seen on it, inside the scoring window.
     */
    public function get_ip_signals(string $ip, string $since): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN h.click_type IS NOT NULL AND h.click_dup = 0 AND h.bot_category <> 'ads'
                    THEN h.click_id END) AS ad_clicks,
                COUNT(DISTINCT CASE WHEN h.click_type IS NOT NULL AND h.click_dup = 0 AND h.bot_category <> 'ads'
                    AND h.is_bot = 1 THEN h.click_id END) AS bot_ad_clicks,
                COUNT(DISTINCT CASE WHEN h.click_type IS NOT NULL AND h.click_dup = 0 AND h.bot_category <> 'ads'
                    AND h.interacted = 0 AND COALESCE(h.engaged_ms, 0) < 5000
                    AND (h.engaged_ms IS NOT NULL OR h.created_at < %s) THEN h.click_id END) AS bounce_clicks,
                MAX(h.is_webdriver) AS webdriver,
                SUM(h.bot_category = 'tool') AS tool_hits
             FROM %i h
             WHERE h.ip = %s AND h.created_at >= %s",
            self::ago(30 * MINUTE_IN_SECONDS),
            $this->hits,
            $ip,
            $since
        ), ARRAY_A);

        // Same browser (GA client id) clicking ads, possibly from several IP addresses.
        $browser = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(MAX(t.clicks), 0) AS browser_clicks, COALESCE(MAX(t.ips), 0) AS browser_ips
             FROM (
                SELECT h.ga_cid, COUNT(DISTINCT h.click_id) AS clicks, COUNT(DISTINCT h.ip) AS ips
                FROM %i h
                WHERE h.created_at >= %s
                  AND h.click_type IS NOT NULL AND h.click_dup = 0 AND h.bot_category <> 'ads'
                  AND h.ga_cid IN (
                      SELECT DISTINCT ga_cid FROM %i WHERE ip = %s AND ga_cid <> '' AND created_at >= %s
                  )
                GROUP BY h.ga_cid
             ) t",
            $this->hits,
            $since,
            $this->hits,
            $ip,
            $since
        ), ARRAY_A);

        return array_map('intval', array_merge($row ?: [], $browser ?: [])) + [
            'ad_clicks'      => 0,
            'bot_ad_clicks'  => 0,
            'bounce_clicks'  => 0,
            'webdriver'      => 0,
            'tool_hits'      => 0,
            'browser_clicks' => 0,
            'browser_ips'    => 0,
        ];
    }

    public function purge(int $days): int {
        global $wpdb;
        $cutoff = self::ago(max(1, $days) * DAY_IN_SECONDS);

        $deleted = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE created_at < %s',
            $this->hits,
            $cutoff
        ));

        // Manually whitelisted / flagged IPs are kept so the decision survives, and IPs
        // still excluded in Google Ads are kept until the sync script removes them.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE last_seen < %s AND status = 'none' AND ads_excluded = 0",
            $this->ips,
            $cutoff
        ));

        return $deleted;
    }

    public function get_last_hit_time(): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SELECT MAX(created_at) FROM %i', $this->hits));
    }

    /* ------------------------------------------------------------------
     * Google Ads sync
     * ---------------------------------------------------------------- */

    /**
     * IPs to exclude in Google Ads: flagged or suspicious, never whitelisted, only IPs that clicked ads.
     */
    public function get_exclusion_ips(int $limit = 500): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT ip FROM %i
             WHERE (status = 'flagged' OR (status = 'none' AND risk_score >= %d)) AND ad_clicks > 0
             ORDER BY (status = 'flagged') DESC, risk_score DESC, ad_clicks DESC
             LIMIT %d",
            $this->ips,
            (int) ClickWarden_Settings::get('suspicious_score'),
            $limit
        ));
    }

    /**
     * IPs the Google Ads script added earlier that are no longer on the exclusion list.
     */
    public function get_ads_unblock(array $block): array {
        global $wpdb;
        $excluded = $wpdb->get_col($wpdb->prepare('SELECT ip FROM %i WHERE ads_excluded = 1', $this->ips));
        return array_values(array_diff($excluded, $block));
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated list of %s.
    public function set_ads_excluded(array $ips, bool $excluded): void {
        global $wpdb;
        $ips = array_values(array_filter($ips, static fn($ip) => (bool) filter_var($ip, FILTER_VALIDATE_IP)));
        foreach (array_chunk($ips, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $wpdb->query($wpdb->prepare(
                    "UPDATE %i SET ads_excluded = %d WHERE ip IN ($placeholders)",
                $this->ips,
                (int) $excluded,
                ...$chunk
            ));
        }
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

    public function count_ads_excluded(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ads_excluded = 1', $this->ips));
    }

    public function get_countries(): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT country_code, MAX(country) AS country, COUNT(*) AS total
             FROM %i
             WHERE country_code <> ''
             GROUP BY country_code
             ORDER BY total DESC",
            $this->ips
        ));
    }

    /* ------------------------------------------------------------------
     * Admin reports
     *
     * The filterable reports assemble their WHERE clause from fixed SQL fragments
     * written in this class. Every user supplied value is added as a placeholder
     * and bound through $wpdb->prepare(); table names are bound with %i; ORDER BY
     * columns come from an allow-list.
     *
     * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
     * ---------------------------------------------------------------- */

    private function suspicious_sql(string $alias = 'i'): string {
        return sprintf(
            "(%1\$s.status = 'flagged' OR (%1\$s.status = 'none' AND %1\$s.risk_score >= %2\$d))",
            $alias,
            (int) ClickWarden_Settings::get('suspicious_score')
        );
    }

    private function ip_filter_sql(string $column, string $ip, array &$where, array &$args): void {
        global $wpdb;
        if (str_ends_with($ip, '*')) {
            $where[] = "$column LIKE %s";
            $args[] = $wpdb->esc_like(rtrim($ip, '*')) . '%';
        } else {
            $where[] = "$column = %s";
            $args[] = $ip;
        }
    }

    private function hit_where(array $f): array {
        global $wpdb;
        $where = ['1=1'];
        $args = [];

        if (!empty($f['date_from'])) {
            $where[] = 'h.created_at >= %s';
            $args[] = get_gmt_from_date($f['date_from'] . ' 00:00:00');
        }
        if (!empty($f['date_to'])) {
            $where[] = 'h.created_at <= %s';
            $args[] = get_gmt_from_date($f['date_to'] . ' 23:59:59');
        }
        if (!empty($f['ip'])) {
            $this->ip_filter_sql('h.ip', $f['ip'], $where, $args);
        }
        if (!empty($f['ga'])) {
            $where[] = 'h.ga_cid = %s';
            $args[] = $f['ga'];
        }
        if (!empty($f['country'])) {
            $where[] = 'i.country_code = %s';
            $args[] = $f['country'];
        }
        if (!empty($f['type'])) {
            if ('human' === $f['type']) {
                $where[] = 'h.is_bot = 0';
            } elseif ('bot' === $f['type']) {
                $where[] = 'h.is_bot = 1';
            } else {
                $where[] = 'h.bot_category = %s';
                $args[] = $f['type'];
            }
        }
        if (!empty($f['ad'])) {
            $where[] = 'h.click_type IS NOT NULL';
        }
        if (!empty($f['risk'])) {
            if ('suspicious' === $f['risk']) {
                $where[] = $this->suspicious_sql();
            } elseif ('watch' === $f['risk']) {
                $where[] = "i.status <> 'whitelisted' AND (i.status = 'flagged' OR i.risk_score >= %d)";
                $args[] = (int) ClickWarden_Settings::get('watch_score');
            }
        }
        if (!empty($f['url'])) {
            $where[] = 'h.url_path LIKE %s';
            $args[] = '%' . $wpdb->esc_like($f['url']) . '%';
        }
        if (!empty($f['campaign'])) {
            if (preg_match('/^#(\d+)$/', $f['campaign'], $m)) {
                $where[] = 'h.gad_campaignid = %s';
                $args[] = $m[1];
            } else {
                $where[] = '(h.utm_campaign LIKE %s OR h.gad_campaignid = %s)';
                $args[] = '%' . $wpdb->esc_like($f['campaign']) . '%';
                $args[] = $f['campaign'];
            }
        }

        return [implode(' AND ', $where), $args];
    }

    public function query_hits(array $filters, string $orderby, string $order, int $per_page, int $page): array {
        global $wpdb;
        $columns = [
            'created_at' => 'h.created_at',
            'ip'         => 'h.ip',
            'engaged_ms' => 'h.engaged_ms',
            'risk_score' => 'i.risk_score',
        ];
        $orderby = $columns[$orderby] ?? 'h.created_at';
        $order = 'ASC' === strtoupper($order) ? 'ASC' : 'DESC';

        [$where, $args] = $this->hit_where($filters);
        $from = "FROM %i h LEFT JOIN %i i ON i.ip = h.ip WHERE $where";
        $args = array_merge([$this->hits, $this->ips], $args);

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) $from", $args));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, i.country_code, i.country, i.city, i.isp, i.asn, i.is_proxy, i.is_hosting,
                    i.is_mobile, i.risk_score, i.risk_reasons, i.status
             $from
             ORDER BY $orderby $order, h.id DESC
             LIMIT %d OFFSET %d",
            array_merge($args, [$per_page, max(0, ($page - 1) * $per_page)])
        ));

        return [$rows ?: [], $total];
    }

    private function ip_where(array $f): array {
        $where = ['1=1'];
        $args = [];
        $watch = (int) ClickWarden_Settings::get('watch_score');
        $suspicious = (int) ClickWarden_Settings::get('suspicious_score');

        if (!empty($f['ip'])) {
            $this->ip_filter_sql('i.ip', $f['ip'], $where, $args);
        }
        if (!empty($f['country'])) {
            $where[] = 'i.country_code = %s';
            $args[] = $f['country'];
        }
        if (!empty($f['level'])) {
            if ('suspicious' === $f['level']) {
                $where[] = $this->suspicious_sql();
            } elseif ('watch' === $f['level']) {
                $where[] = "i.status = 'none' AND i.risk_score >= %d AND i.risk_score < %d";
                array_push($args, $watch, $suspicious);
            } elseif ('clean' === $f['level']) {
                $where[] = "i.status = 'none' AND i.risk_score < %d";
                $args[] = $watch;
            }
        }
        if (!empty($f['status'])) {
            $where[] = 'i.status = %s';
            $args[] = $f['status'];
        }
        if (!empty($f['network'])) {
            $where[] = match ($f['network']) {
                'hosting'     => 'i.is_hosting = 1',
                'proxy'       => 'i.is_proxy = 1',
                'mobile'      => 'i.is_mobile = 1',
                'residential' => "i.is_hosting = 0 AND i.is_proxy = 0 AND i.geo_status = 'done'",
                default       => '1=1',
            };
        }
        if (!empty($f['ads'])) {
            $where[] = 'i.ads_excluded = 1';
        }
        if (!empty($f['min_clicks'])) {
            $where[] = 'i.ad_clicks >= %d';
            $args[] = (int) $f['min_clicks'];
        }
        if (!empty($f['date_from'])) {
            $where[] = 'i.last_seen >= %s';
            $args[] = get_gmt_from_date($f['date_from'] . ' 00:00:00');
        }
        if (!empty($f['date_to'])) {
            $where[] = 'i.last_seen <= %s';
            $args[] = get_gmt_from_date($f['date_to'] . ' 23:59:59');
        }

        return [implode(' AND ', $where), $args];
    }

    public function query_ips(array $filters, string $orderby, string $order, int $per_page, int $page): array {
        global $wpdb;
        $columns = ['risk_score', 'ad_clicks', 'hits', 'last_seen', 'first_seen', 'ip', 'country_code'];
        $orderby = in_array($orderby, $columns, true) ? "i.$orderby" : 'i.risk_score';
        $order = 'ASC' === strtoupper($order) ? 'ASC' : 'DESC';

        [$where, $args] = $this->ip_where($filters);
        $args = array_merge([$this->ips], $args);

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i i WHERE $where", $args));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.* FROM %i i WHERE $where ORDER BY $orderby $order, i.last_seen DESC LIMIT %d OFFSET %d",
            array_merge($args, [$per_page, max(0, ($page - 1) * $per_page)])
        ));

        return [$rows ?: [], $total];
    }

    public function get_overview(): array {
        global $wpdb;
        $suspicious = $this->suspicious_sql();
        $ad_click = self::AD_CLICK_SQL;
        $campaign = self::CAMPAIGN_SQL;
        $today = get_gmt_from_date(wp_date('Y-m-d') . ' 00:00:00');
        $week = self::ago(7 * DAY_IN_SECONDS);
        $offset = (int) wp_timezone()->getOffset(new DateTimeImmutable('now'));

        $today_row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS ad_clicks, COALESCE(SUM($suspicious), 0) AS suspicious_clicks
             FROM %i h LEFT JOIN %i i ON i.ip = h.ip
             WHERE h.created_at >= %s AND $ad_click",
            $this->hits,
            $this->ips,
            $today
        ));

        $week_row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS hits,
                    COALESCE(SUM(h.is_bot), 0) AS bot_hits,
                    COALESCE(SUM($ad_click), 0) AS ad_clicks,
                    COALESCE(SUM($ad_click AND (i.is_hosting = 1 OR i.is_proxy = 1)), 0) AS network_clicks,
                    COALESCE(SUM($ad_click AND $suspicious), 0) AS suspicious_clicks
             FROM %i h LEFT JOIN %i i ON i.ip = h.ip
             WHERE h.created_at >= %s",
            $this->hits,
            $this->ips,
            $week
        ));

        $ip_row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM($suspicious AND i.ad_clicks > 0), 0) AS suspicious_ips,
                    COALESCE(SUM(i.status = 'none' AND i.risk_score >= %d AND i.risk_score < %d AND i.ad_clicks > 0), 0) AS watch_ips
             FROM %i i",
            (int) ClickWarden_Settings::get('watch_score'),
            (int) ClickWarden_Settings::get('suspicious_score'),
            $this->ips
        ));

        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(DATE_ADD(h.created_at, INTERVAL %d SECOND)) AS day,
                    COUNT(*) AS ad_clicks,
                    COALESCE(SUM($suspicious), 0) AS suspicious_clicks
             FROM %i h LEFT JOIN %i i ON i.ip = h.ip
             WHERE h.created_at >= %s AND $ad_click
             GROUP BY day",
            $offset,
            $this->hits,
            $this->ips,
            self::ago(14 * DAY_IN_SECONDS)
        ), OBJECT_K);

        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS);
            $series[$day] = [
                'ad_clicks'         => (int) ($daily[$day]->ad_clicks ?? 0),
                'suspicious_clicks' => (int) ($daily[$day]->suspicious_clicks ?? 0),
            ];
        }

        $top_ips = $wpdb->get_results($wpdb->prepare(
            "SELECT i.* FROM %i i
             WHERE i.status <> 'whitelisted' AND i.ad_clicks > 0
             ORDER BY (i.status = 'flagged') DESC, i.risk_score DESC, i.ad_clicks DESC
             LIMIT 10",
            $this->ips
        ));

        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT $campaign AS campaign,
                    COUNT(*) AS ad_clicks,
                    COALESCE(SUM($suspicious), 0) AS suspicious_clicks
             FROM %i h LEFT JOIN %i i ON i.ip = h.ip
             WHERE h.created_at >= %s AND $ad_click
             GROUP BY campaign
             ORDER BY suspicious_clicks DESC, ad_clicks DESC
             LIMIT 10",
            $this->hits,
            $this->ips,
            $week
        ));

        return [
            'today'     => $today_row,
            'week'      => $week_row,
            'ips'       => $ip_row,
            'series'    => $series,
            'top_ips'   => $top_ips ?: [],
            'campaigns' => $campaigns ?: [],
        ];
    }
}
