<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoints for the Google Ads script that keeps campaign IP exclusions in sync.
 *
 *  POST /clickwarden/v1/ads/list  -> campaigns to manage, IPs to block and IPs to unblock
 *  POST /clickwarden/v1/ads/ack   -> the script reports which IPs it actually added/removed
 *
 * Both require the secret token in the X-ClickWarden-Token header. POST keeps the
 * responses out of LiteSpeed Cache and the Hostinger CDN.
 */
class ClickWarden_Ads_Sync {
    public const TOKEN_OPTION = 'clickwarden_ads_token';
    public const STATUS_OPTION = 'clickwarden_ads_sync';
    public const LIMIT = 500;

    public function __construct(private ClickWarden_Database $database) {}

    public function register(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public static function get_token(): string {
        $token = (string) get_option(self::TOKEN_OPTION, '');
        if ('' === $token) {
            $token = self::regenerate_token();
        }
        return $token;
    }

    public static function regenerate_token(): string {
        $token = wp_generate_password(40, false, false);
        update_option(self::TOKEN_OPTION, $token, false);
        return $token;
    }

    public static function endpoint(): string {
        return rest_url('clickwarden/v1/ads/');
    }

    public static function status(): array {
        $status = get_option(self::STATUS_OPTION, []);
        return is_array($status) ? $status : [];
    }

    public function register_routes(): void {
        $ip_list = [
            'type'     => 'array',
            'items'    => ['type' => 'string', 'maxLength' => 45],
            'maxItems' => 5000,
            'default'  => [],
        ];

        register_rest_route('clickwarden/v1', '/ads/list', [
            'methods'             => 'POST',
            'callback'            => [$this, 'list_ips'],
            'permission_callback' => [$this, 'check_token'],
        ]);

        register_rest_route('clickwarden/v1', '/ads/ack', [
            'methods'             => 'POST',
            'callback'            => [$this, 'ack'],
            'permission_callback' => [$this, 'check_token'],
            'args'                => [
                'added'     => $ip_list,
                'removed'   => $ip_list,
                'campaigns' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 255], 'maxItems' => 50, 'default' => []],
                'missing'   => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 255], 'maxItems' => 50, 'default' => []],
                'errors'    => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 500], 'maxItems' => 50, 'default' => []],
            ],
        ]);
    }

    public function check_token(WP_REST_Request $request): bool|WP_Error {
        $sent = (string) $request->get_header('x_clickwarden_token');
        $token = (string) get_option(self::TOKEN_OPTION, '');

        if ('' === $token || '' === $sent || !hash_equals($token, $sent)) {
            return new WP_Error('clickwarden_forbidden', 'Invalid token.', ['status' => 403]);
        }
        return true;
    }

    private function response(array $data): WP_REST_Response {
        $response = new WP_REST_Response($data, 200);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $response;
    }

    public function list_ips(): WP_REST_Response {
        $block = $this->database->get_exclusion_ips(self::LIMIT);

        return $this->response([
            'campaigns'    => ClickWarden_Settings::get_lines('ads_campaigns'),
            'limit'        => self::LIMIT,
            'block'        => $block,
            'unblock'      => $this->database->get_ads_unblock($block),
            'generated_at' => gmdate('c'),
        ]);
    }

    public function ack(WP_REST_Request $request): WP_REST_Response {
        $clean = static fn($list) => array_values(array_filter(
            array_map(static fn($v) => sanitize_text_field((string) $v), (array) $list),
            'strlen'
        ));

        $added = $clean($request['added']);
        $removed = $clean($request['removed']);

        $this->database->set_ads_excluded($added, true);
        $this->database->set_ads_excluded($removed, false);

        update_option(self::STATUS_OPTION, [
            'time'      => time(),
            'added'     => count($added),
            'removed'   => count($removed),
            'campaigns' => $clean($request['campaigns']),
            'missing'   => $clean($request['missing']),
            'errors'    => array_slice($clean($request['errors']), 0, 10),
        ], false);

        return $this->response(['ok' => true, 'excluded' => $this->database->count_ads_excluded()]);
    }

    /**
     * The Google Ads script with this site's endpoint and token filled in.
     */
    public static function script(): string {
        $template = (string) file_get_contents(CLICKWARDEN_PLUGIN_DIR . 'assets/google-ads-script.js');
        return strtr($template, [
            '{{ENDPOINT}}' => self::endpoint(),
            '{{TOKEN}}'    => self::get_token(),
        ]);
    }
}
