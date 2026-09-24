<?php

if (!defined('ABSPATH')) {
    exit;
}

class ClickWarden_Settings {
    public const OPTION = 'clickwarden_settings';
    public const GROUP = 'clickwarden_settings_group';

    private static ?array $cache = null;

    public static function defaults(): array {
        return [
            'retention_days'     => 30,
            'target_countries'   => '',
            'click_threshold'    => 3,
            'click_window_hours' => 24,
            'suspicious_score'   => 60,
            'watch_score'        => 30,
            'rate_limit'         => 60,
            'excluded_ips'       => '',
            'trusted_proxies'    => '',
            'geo_enabled'        => 0,
            'ipapiis_key'        => '',
            'ipapi_fallback'     => 0,
            'ipapi_key'          => '',
            'ads_campaigns'      => '',
        ];
    }

    public static function all(): array {
        if (null === self::$cache) {
            $stored = get_option(self::OPTION, []);
            self::$cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }
        return self::$cache;
    }

    public static function get(string $key): mixed {
        return self::all()[$key] ?? null;
    }

    /**
     * Splits a textarea/comma separated setting into a clean list.
     */
    public static function get_list(string $key): array {
        $value = (string) self::get($key);
        $items = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('trim', $items ?: [])));
    }

    public static function register(): void {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => self::defaults(),
        ]);

        add_action('update_option_' . self::OPTION, static function () {
            self::$cache = null;
        });
    }

    public static function sanitize($input): array {
        // Settings are split over several forms: keep values a form did not submit.
        $input = array_merge(self::all(), is_array($input) ? $input : []);
        $defaults = self::defaults();
        $clean = [];

        $ints = [
            'retention_days'     => [1, 365],
            'click_threshold'    => [2, 50],
            'click_window_hours' => [1, 720],
            'suspicious_score'   => [10, 100],
            'watch_score'        => [5, 99],
            'rate_limit'         => [10, 1000],
        ];

        foreach ($ints as $key => [$min, $max]) {
            $value = isset($input[$key]) ? (int) $input[$key] : $defaults[$key];
            $clean[$key] = max($min, min($max, $value));
        }

        if ($clean['watch_score'] >= $clean['suspicious_score']) {
            $clean['watch_score'] = max(5, $clean['suspicious_score'] - 10);
        }

        $countries = preg_split('/[\s,]+/', strtoupper((string) ($input['target_countries'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $countries = array_filter($countries ?: [], static fn($c) => (bool) preg_match('/^[A-Z]{2}$/', $c));
        $clean['target_countries'] = implode(', ', array_unique($countries));

        foreach (['excluded_ips', 'trusted_proxies'] as $key) {
            $items = preg_split('/[\s,]+/', (string) ($input[$key] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $items = array_filter($items ?: [], [self::class, 'is_valid_ip_rule']);
            $clean[$key] = implode("\n", array_unique($items));
        }

        $campaigns = array_map(
            static fn($name) => mb_substr(trim(sanitize_text_field($name)), 0, 255),
            explode("\n", str_replace("\r", '', (string) ($input['ads_campaigns'] ?? '')))
        );
        $clean['ads_campaigns'] = implode("\n", array_unique(array_filter($campaigns, 'strlen')));

        foreach (['geo_enabled', 'ipapi_fallback'] as $key) {
            $clean[$key] = empty($input[$key]) ? 0 : 1;
        }

        foreach (['ipapiis_key', 'ipapi_key'] as $key) {
            $clean[$key] = preg_replace('/[^A-Za-z0-9]/', '', (string) ($input[$key] ?? ''));
        }

        self::$cache = null;

        return $clean;
    }

    /**
     * Accepts a single IP or a CIDR range (IPv4/IPv6).
     */
    public static function is_valid_ip_rule(string $rule): bool {
        if (str_contains($rule, '/')) {
            [$subnet, $bits] = explode('/', $rule, 2);
            if (!ctype_digit($bits) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
                return false;
            }
            $max = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
            return (int) $bits <= $max;
        }
        return (bool) filter_var($rule, FILTER_VALIDATE_IP);
    }

    public static function ip_matches(string $ip, array $rules): bool {
        $ip_bin = @inet_pton($ip);
        if (false === $ip_bin) {
            return false;
        }

        foreach ($rules as $rule) {
            if (!str_contains($rule, '/')) {
                if ($ip_bin === @inet_pton($rule)) {
                    return true;
                }
                continue;
            }

            [$subnet, $bits] = explode('/', $rule, 2);
            $subnet_bin = @inet_pton($subnet);
            if (false === $subnet_bin || strlen($subnet_bin) !== strlen($ip_bin)) {
                continue;
            }

            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if (substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
                continue;
            }
            if ($remainder === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($ip_bin[$bytes]) & $mask) === (ord($subnet_bin[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    public static function get_lines(string $key): array {
        $lines = array_map('trim', explode("\n", (string) self::get($key)));
        return array_values(array_filter($lines, 'strlen'));
    }

    public static function is_excluded_ip(string $ip): bool {
        return self::ip_matches($ip, self::get_list('excluded_ips'));
    }
}
