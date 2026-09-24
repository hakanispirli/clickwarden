<?php

if (!defined('ABSPATH')) {
    exit;
}

class ClickWarden_IP_Info {
    private const IP_API_FIELDS = 'status,message,query,country,countryCode,city,isp,org,as,mobile,proxy,hosting';
    private const BATCH_SIZE = 100;

    /** Transients holding the unix time until which a provider is paused. */
    public const BACKOFF_IPAPIIS = 'clickwarden_geo_backoff_ipapiis';
    public const BACKOFF_PROXYCHECK = 'clickwarden_geo_backoff_proxycheck';
    public const BACKOFF_IPAPI = 'clickwarden_geo_backoff';

    /** Primary providers the site owner can choose from: slug => [label, backoff transient]. */
    private const PROVIDERS = [
        'ipapiis'    => ['ipapi.is', self::BACKOFF_IPAPIIS],
        'proxycheck' => ['proxycheck.io', self::BACKOFF_PROXYCHECK],
    ];

    public static function providers(): array {
        return array_map(static fn(array $provider): string => $provider[0], self::PROVIDERS);
    }

    public static function primary(): string {
        $provider = (string) ClickWarden_Settings::get('geo_provider');
        return isset(self::PROVIDERS[$provider]) ? $provider : 'ipapiis';
    }

    public static function primary_label(): string {
        return self::PROVIDERS[self::primary()][0];
    }

    /**
     * @return int Unix time until which the selected provider is paused, 0 when it is active.
     */
    public static function primary_paused_until(): int {
        return (int) get_transient(self::PROVIDERS[self::primary()][1]);
    }

    /**
     * Only REMOTE_ADDR is trusted. Forwarded headers are read solely when the
     * request comes from a proxy the site owner explicitly listed, otherwise
     * any visitor could spoof their IP with a single header.
     */
    public static function get_client_ip(): string {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '';
        }

        $trusted = ClickWarden_Settings::get_list('trusted_proxies');
        if (!$trusted || !ClickWarden_Settings::ip_matches($remote, $trusted) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }

        // Walk from the closest hop outwards, the first untrusted address is the client.
        $forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        $chain = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($chain as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                break;
            }
            if (!ClickWarden_Settings::ip_matches($ip, $trusted)) {
                return $ip;
            }
        }

        return $remote;
    }

    public static function is_public_ip(string $ip): bool {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function pause(string $transient, int $seconds): void {
        set_transient($transient, time() + $seconds, $seconds);
    }

    /**
     * Cron worker: resolves queued IPs with the provider the site owner selected
     * (ipapi.is or proxycheck.io, both with VPN/Tor/datacenter detection); the
     * optional ip-api.com fallback takes over for whatever the primary provider
     * could not resolve, e.g. when its daily quota runs out during an attack.
     */
    public static function process_queue(ClickWarden_Database $db): void {
        if (!ClickWarden_Settings::get('geo_enabled') || get_transient('clickwarden_geo_lock')) {
            return;
        }
        $primary = self::primary();
        $primary_paused = self::primary_paused_until() > 0;
        $ipapi_paused = !ClickWarden_Settings::get('ipapi_fallback') || get_transient(self::BACKOFF_IPAPI);
        if ($primary_paused && $ipapi_paused) {
            return;
        }
        set_transient('clickwarden_geo_lock', 1, 50);

        try {
            $queue = $db->get_geo_queue(self::BATCH_SIZE);
            $lookup = [];
            $now = ClickWarden_Database::now();

            foreach ($queue as $ip) {
                if (self::is_public_ip($ip)) {
                    $lookup[] = $ip;
                    continue;
                }
                $db->update_ip($ip, [
                    'country'        => 'Private network',
                    'geo_status'     => 'done',
                    'geo_updated_at' => $now,
                ]);
            }

            if (!$lookup) {
                return;
            }

            $results = [];
            if (!$primary_paused) {
                $results = ('proxycheck' === $primary ? self::request_proxycheck($lookup) : self::request_ipapiis($lookup)) ?? [];
            }

            $remaining = array_values(array_diff($lookup, array_keys($results)));
            if ($remaining && !$ipapi_paused) {
                $results += self::request_ipapi($remaining) ?? [];
            }

            foreach ($lookup as $ip) {
                if (!isset($results[$ip])) {
                    // Retried after an hour (see ClickWarden_Database::get_geo_queue()).
                    $db->update_ip($ip, ['geo_status' => 'failed', 'geo_updated_at' => $now]);
                    continue;
                }

                $db->update_ip($ip, $results[$ip] + ['geo_status' => 'done', 'geo_updated_at' => $now]);
                ClickWarden_Risk_Scorer::rescore($ip, $db);
            }
        } finally {
            delete_transient('clickwarden_geo_lock');
        }
    }

    private static function text($value, int $max): string {
        return mb_substr(sanitize_text_field((string) $value), 0, $max);
    }

    /**
     * @return array<string, array>|null Normalised rows keyed by IP, null when the provider failed.
     */
    private static function request_ipapiis(array $ips): ?array {
        $body = ['ips' => array_values($ips)];
        $key = (string) ClickWarden_Settings::get('ipapiis_key');
        if ('' !== $key) {
            $body['key'] = $key;
        }

        $response = wp_remote_post('https://api.ipapi.is', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);

        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $data = $code ? json_decode(wp_remote_retrieve_body($response), true) : null;

        if (200 !== $code || !is_array($data) || isset($data['error'])) {
            // Quota exhausted, invalid key or outage: let the fallback handle lookups for a while.
            self::pause(self::BACKOFF_IPAPIIS, 429 === $code || 403 === $code || isset($data['error']) ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
            return null;
        }

        $rows = [];
        foreach ($ips as $ip) {
            $item = $data[$ip] ?? null;
            if (!is_array($item) || empty($item['location'])) {
                continue;
            }

            $location = (array) $item['location'];
            $company = (array) ($item['company'] ?? []);
            $asn = (array) ($item['asn'] ?? []);
            $asn_org = (string) ($asn['org'] ?? '');

            $rows[$ip] = [
                'country_code' => strtoupper(substr(self::text($location['country_code'] ?? '', 2), 0, 2)),
                'country'      => self::text($location['country'] ?? '', 100),
                'city'         => self::text($location['city'] ?? '', 100),
                'isp'          => self::text($company['name'] ?? $asn_org, 150),
                'org'          => self::text($asn_org, 150),
                'asn'          => self::text(empty($asn['asn']) ? '' : 'AS' . (int) $asn['asn'] . ' ' . $asn_org, 150),
                'is_proxy'     => (!empty($item['is_proxy']) || !empty($item['is_vpn']) || !empty($item['is_tor'])) ? 1 : 0,
                'is_hosting'   => empty($item['is_datacenter']) ? 0 : 1,
                'is_mobile'    => empty($item['is_mobile']) ? 0 : 1,
            ];
        }

        return $rows;
    }

    /**
     * proxycheck.io v3: up to 1,000 IPs per POST, 100 lookups a day without a key, 1,000 with a free key.
     *
     * @return array<string, array>|null Normalised rows keyed by IP, null when the provider failed.
     */
    private static function request_proxycheck(array $ips): ?array {
        $key = (string) ClickWarden_Settings::get('proxycheck_key');
        // A POST without any query string is redirected to a lookup of the server's own IP, so p=0 (compact JSON) is always sent.
        $args = '' !== $key ? ['key' => $key, 'p' => 0] : ['p' => 0];
        $url = add_query_arg($args, 'https://proxycheck.io/v3/');

        $response = wp_remote_post($url, [
            'timeout'     => 15,
            'redirection' => 0,
            'body'        => ['ips' => implode(',', $ips)],
        ]);

        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $data = $code ? json_decode(wp_remote_retrieve_body($response), true) : null;
        $status = is_array($data) ? (string) ($data['status'] ?? '') : '';

        if (200 !== $code || !in_array($status, ['ok', 'warning'], true)) {
            // "denied" means quota exhausted or a disabled key: wait longer than after an outage.
            self::pause(self::BACKOFF_PROXYCHECK, 'denied' === $status || in_array($code, [401, 403, 429], true) ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
            return null;
        }

        $rows = [];
        foreach ($ips as $ip) {
            $item = $data[$ip] ?? null;
            if (!is_array($item) || empty($item['location']) || empty($item['network'])) {
                continue;
            }

            $location = (array) $item['location'];
            $network = (array) $item['network'];
            $detections = (array) ($item['detections'] ?? []);
            $type = (string) ($network['type'] ?? '');
            $provider = self::text($network['provider'] ?? '', 150);
            $asn = self::text($network['asn'] ?? '', 20);

            $rows[$ip] = [
                'country_code' => strtoupper(substr(self::text($location['country_code'] ?? '', 2), 0, 2)),
                'country'      => self::text($location['country_name'] ?? '', 100),
                'city'         => self::text($location['city_name'] ?? '', 100),
                'isp'          => $provider,
                'org'          => self::text($network['organisation'] ?? $provider, 150),
                'asn'          => '' === $asn ? '' : mb_substr(trim($asn . ' ' . $provider), 0, 150),
                'is_proxy'     => (!empty($detections['proxy']) || !empty($detections['vpn']) || !empty($detections['tor'])) ? 1 : 0,
                'is_hosting'   => (!empty($detections['hosting']) || 'Hosting' === $type) ? 1 : 0,
                'is_mobile'    => 'Wireless' === $type ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array>|null Normalised rows keyed by IP, null when the provider failed.
     */
    private static function request_ipapi(array $ips): ?array {
        $key = (string) ClickWarden_Settings::get('ipapi_key');

        // The free endpoint is HTTP only; the paid endpoint supports HTTPS.
        $url = $key
            ? add_query_arg(['key' => $key, 'fields' => self::IP_API_FIELDS], 'https://pro.ip-api.com/batch')
            : add_query_arg(['fields' => self::IP_API_FIELDS], 'http://ip-api.com/batch');

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(array_values($ips)),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $remaining = wp_remote_retrieve_header($response, 'x-rl');
        $ttl = (int) wp_remote_retrieve_header($response, 'x-ttl');

        if (429 === $code || ('' !== $remaining && (int) $remaining <= 0)) {
            self::pause(self::BACKOFF_IPAPI, max(10, $ttl));
        }

        $data = 200 === $code ? json_decode(wp_remote_retrieve_body($response), true) : null;
        if (!is_array($data)) {
            return null;
        }

        $rows = [];
        foreach ($data as $item) {
            $ip = (string) ($item['query'] ?? '');
            if (!in_array($ip, $ips, true) || ($item['status'] ?? '') !== 'success') {
                continue;
            }

            $rows[$ip] = [
                'country_code' => substr(self::text($item['countryCode'] ?? '', 2), 0, 2),
                'country'      => self::text($item['country'] ?? '', 100),
                'city'         => self::text($item['city'] ?? '', 100),
                'isp'          => self::text($item['isp'] ?? '', 150),
                'org'          => self::text($item['org'] ?? '', 150),
                'asn'          => self::text($item['as'] ?? '', 150),
                'is_proxy'     => empty($item['proxy']) ? 0 : 1,
                'is_hosting'   => empty($item['hosting']) ? 0 : 1,
                'is_mobile'    => empty($item['mobile']) ? 0 : 1,
            ];
        }

        return $rows;
    }
}
