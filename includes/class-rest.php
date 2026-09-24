<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public beacon endpoints. They have to be unauthenticated (cached pages cannot
 * carry a valid nonce), so everything coming from the client is treated as
 * untrusted: the IP and user agent are taken from the server, payloads are
 * validated and every IP is rate limited.
 */
class ClickWarden_REST {
    private const NAMESPACE = 'clickwarden/v1';
    private const CLICK_PARAMS = ['gclid', 'gbraid', 'wbraid'];
    private const UTM_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term'];
    private const COOKIE_FIELDS = ['gcl_au' => 64, 'gcl_aw' => 255, 'gcl_gb' => 255, 'gcl_gs' => 128];
    private const ENGAGE_WINDOW = 2 * HOUR_IN_SECONDS;

    public function __construct(private ClickWarden_Database $database) {}

    public function register(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    private static function string_arg(int $max): array {
        return ['type' => 'string', 'maxLength' => $max, 'default' => ''];
    }

    private static function cookie_args(): array {
        $args = ['ga' => self::string_arg(64)];
        foreach (self::COOKIE_FIELDS as $field => $max) {
            $args[$field] = self::string_arg(500);
        }
        return $args;
    }

    public function register_routes(): void {
        $uid = ['type' => 'string', 'required' => true, 'pattern' => '^[0-9a-fA-F-]{36}$'];

        $hit_args = [
            'uid'            => $uid,
            'path'           => self::string_arg(2048),
            'ref'            => self::string_arg(2048),
            'wd'             => ['type' => 'boolean', 'default' => false],
            'gad_source'     => self::string_arg(20),
            'gad_campaignid' => self::string_arg(40),
        ];
        foreach (array_merge(self::CLICK_PARAMS, self::UTM_PARAMS) as $param) {
            $hit_args[$param] = self::string_arg(500);
        }

        register_rest_route(self::NAMESPACE, '/hit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'hit'],
            'permission_callback' => '__return_true',
            'args'                => $hit_args + self::cookie_args(),
        ]);

        register_rest_route(self::NAMESPACE, '/engage', [
            'methods'             => 'POST',
            'callback'            => [$this, 'engage'],
            'permission_callback' => '__return_true',
            'args'                => [
                'uid' => $uid,
                'ms'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'i'   => ['type' => 'boolean', 'default' => false],
                's'   => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 0],
            ] + self::cookie_args(),
        ]);
    }

    private function empty_response(int $status = 204): WP_REST_Response {
        $response = new WP_REST_Response(null, $status);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $response;
    }

    /**
     * Beacons are sent without a REST nonce, so WordPress treats them as logged out.
     * Read the auth cookie directly to avoid recording staff visits.
     */
    private function is_staff_request(): bool {
        $user_id = wp_validate_auth_cookie('', 'logged_in');
        return $user_id && user_can($user_id, 'edit_posts');
    }

    private function clean_token(string $value, int $max = 255): string {
        return substr(preg_replace('/[^A-Za-z0-9_\-.~$]/', '', $value), 0, $max);
    }

    private function clean_text(string $value, int $max = 100): string {
        return mb_substr(sanitize_text_field($value), 0, $max);
    }

    private function clean_path(string $path): string {
        $path = (string) wp_parse_url($path, PHP_URL_PATH);
        if ('' === $path || '/' !== $path[0]) {
            $path = '/';
        }
        return mb_substr(sanitize_text_field(rawurldecode($path)), 0, 255);
    }

    /**
     * Google cookie values, reduced to the characters they can legitimately contain.
     */
    private function cookie_values(WP_REST_Request $request): array {
        $ga = (string) $request['ga'];
        $values = ['ga_cid' => preg_match('/^\d{1,20}\.\d{1,20}$/', $ga) ? $ga : ''];
        foreach (self::COOKIE_FIELDS as $field => $max) {
            $values[$field] = $this->clean_token((string) $request[$field], $max);
        }
        return $values;
    }

    private function rescore_browser(string $ip, string $ga_cid): void {
        $since = ClickWarden_Database::ago((int) ClickWarden_Settings::get('click_window_hours') * HOUR_IN_SECONDS);
        $ips = array_unique(array_merge([$ip], $this->database->get_ips_for_browser($ga_cid, $since)));
        foreach ($ips as $related) {
            ClickWarden_Risk_Scorer::rescore($related, $this->database);
        }
    }

    public function hit(WP_REST_Request $request): WP_REST_Response {
        $ip = ClickWarden_IP_Info::get_client_ip();
        if ('' === $ip || ClickWarden_Settings::is_excluded_ip($ip) || $this->is_staff_request()) {
            return $this->empty_response();
        }

        $limit = (int) ClickWarden_Settings::get('rate_limit');
        if ($this->database->count_recent_hits($ip, MINUTE_IN_SECONDS) >= $limit) {
            $this->database->mark_rate_limited($ip);
            return $this->empty_response(429);
        }

        $uid = strtolower((string) $request['uid']);
        if ($this->database->hit_uid_exists($uid)) {
            return $this->empty_response();
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $bot = ClickWarden_Bot_Detector::detect($user_agent);

        $click_type = null;
        $click_id = null;
        foreach (self::CLICK_PARAMS as $param) {
            $value = $this->clean_token((string) $request[$param]);
            if ('' !== $value) {
                $click_type = $param;
                $click_id = $value;
                break;
            }
        }

        // A reload of the landing page keeps the click id: Google charges it once, count it once.
        $click_dup = null !== $click_id && $this->database->click_id_exists($click_id);

        $referrer = esc_url_raw((string) $request['ref'], ['http', 'https']);
        $now = ClickWarden_Database::now();
        $cookies = $this->cookie_values($request);

        $data = [
            'hit_uid'        => $uid,
            'ip'             => $ip,
            'url_path'       => $this->clean_path((string) $request['path']),
            'referrer'       => substr($referrer, 0, 500),
            'user_agent'     => mb_substr($user_agent, 0, 500),
            'click_type'     => $click_type,
            'click_id'       => $click_id,
            'click_dup'      => $click_dup ? 1 : 0,
            'gad_source'     => $this->clean_token((string) $request['gad_source'], 10),
            'gad_campaignid' => preg_replace('/\D/', '', substr((string) $request['gad_campaignid'], 0, 32)),
            'is_bot'         => $bot['is_bot'] ? 1 : 0,
            'bot_category'   => $bot['category'],
            'bot_name'       => $bot['name'],
            'is_webdriver'   => $request['wd'] ? 1 : 0,
            'created_at'     => $now,
        ] + $cookies;
        foreach (self::UTM_PARAMS as $param) {
            $data[$param] = $this->clean_text((string) $request[$param]);
        }

        if (!$this->database->insert_hit($data)) {
            return $this->empty_response();
        }

        $is_ad_click = null !== $click_type && !$click_dup && 'ads' !== $bot['category'];
        $this->database->touch_ip($ip, $is_ad_click, $now);

        if ($is_ad_click || $data['is_webdriver'] || 'tool' === $bot['category']) {
            $this->rescore_browser($ip, $cookies['ga_cid']);
        }

        return $this->empty_response();
    }

    public function engage(WP_REST_Request $request): WP_REST_Response {
        $ip = ClickWarden_IP_Info::get_client_ip();
        if ('' === $ip) {
            return $this->empty_response();
        }

        $hit = $this->database->update_engagement(
            strtolower((string) $request['uid']),
            $ip,
            [
                'engaged_ms' => min((int) $request['ms'], 30 * MINUTE_IN_SECONDS * 1000),
                'interacted' => (bool) $request['i'],
                'scroll_pct' => (int) $request['s'],
            ] + $this->cookie_values($request),
            ClickWarden_Database::ago(self::ENGAGE_WINDOW)
        );

        if ($hit && null !== $hit->click_type) {
            $this->rescore_browser($ip, (string) $hit->ga_cid);
        }

        return $this->empty_response();
    }
}
