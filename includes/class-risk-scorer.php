<?php

if (!defined('ABSPATH')) {
    exit;
}

class ClickWarden_Risk_Scorer {
    public static function reason_labels(): array {
        return [
            'repeat_clicks' => __('Repeated ad clicks', 'clickwarden'),
            'multi_clicks'  => __('Multiple ad clicks', 'clickwarden'),
            'ip_rotation'   => __('Same browser, several IPs', 'clickwarden'),
            'datacenter'    => __('Datacenter / hosting IP', 'clickwarden'),
            'proxy'         => __('Proxy / VPN / Tor', 'clickwarden'),
            'automation'    => __('Automated browser', 'clickwarden'),
            'bot_click'     => __('Bot clicked an ad', 'clickwarden'),
            'no_engagement' => __('Ad click without engagement', 'clickwarden'),
            'foreign'       => __('Outside target countries', 'clickwarden'),
            'rate_limit'    => __('Request flood', 'clickwarden'),
        ];
    }

    /**
     * @param object $ip      Row from the ips table.
     * @param array  $signals Output of ClickWarden_Database::get_ip_signals().
     * @return array{score: int, reasons: string[]}
     */
    public static function score(object $ip, array $signals): array {
        if ('whitelisted' === $ip->status) {
            return ['score' => 0, 'reasons' => []];
        }

        $threshold = (int) ClickWarden_Settings::get('click_threshold');
        $window_start = ClickWarden_Database::ago((int) ClickWarden_Settings::get('click_window_hours') * HOUR_IN_SECONDS);
        $score = 0;
        $reasons = [];

        // Clicks are counted per IP and per browser (GA client id), so changing IPs does not reset them.
        $clicks = max($signals['ad_clicks'], $signals['browser_clicks'] ?? 0);

        if ($clicks >= $threshold) {
            $score += 40;
            $reasons[] = 'repeat_clicks';
        } elseif ($clicks >= 2) {
            $score += 20;
            $reasons[] = 'multi_clicks';
        }

        if (($signals['browser_ips'] ?? 0) >= 2) {
            $score += 30;
            $reasons[] = 'ip_rotation';
        }

        if (!empty($ip->is_hosting)) {
            $score += 30;
            $reasons[] = 'datacenter';
        }

        if (!empty($ip->is_proxy)) {
            $score += 30;
            $reasons[] = 'proxy';
        }

        if ($signals['webdriver'] > 0 || $signals['tool_hits'] > 0) {
            $score += 50;
            $reasons[] = 'automation';
        }

        if ($signals['bot_ad_clicks'] > 0) {
            $score += 40;
            $reasons[] = 'bot_click';
        }

        if ($signals['bounce_clicks'] > 0) {
            $score += min(40, 20 * $signals['bounce_clicks']);
            $reasons[] = 'no_engagement';
        }

        $targets = ClickWarden_Settings::get_list('target_countries');
        if ($targets && '' !== $ip->country_code && !in_array($ip->country_code, $targets, true)) {
            $score += 20;
            $reasons[] = 'foreign';
        }

        if (!empty($ip->rate_limited_at) && $ip->rate_limited_at >= $window_start) {
            $score += 20;
            $reasons[] = 'rate_limit';
        }

        return ['score' => min(100, $score), 'reasons' => $reasons];
    }

    public static function rescore(string $ip, ClickWarden_Database $db): void {
        $row = $db->get_ip($ip);
        if (!$row) {
            return;
        }

        $since = ClickWarden_Database::ago((int) ClickWarden_Settings::get('click_window_hours') * HOUR_IN_SECONDS);
        $result = self::score($row, $db->get_ip_signals($ip, $since));
        $reasons = implode(',', $result['reasons']);

        if ((int) $row->risk_score !== $result['score'] || $row->risk_reasons !== $reasons) {
            $db->update_ip($ip, ['risk_score' => $result['score'], 'risk_reasons' => $reasons]);
        }
    }

    /**
     * @return string suspicious|watch|clean|whitelisted
     */
    public static function level(object $ip): string {
        if ('whitelisted' === $ip->status) {
            return 'whitelisted';
        }
        if ('flagged' === $ip->status || (int) $ip->risk_score >= (int) ClickWarden_Settings::get('suspicious_score')) {
            return 'suspicious';
        }
        if ((int) $ip->risk_score >= (int) ClickWarden_Settings::get('watch_score')) {
            return 'watch';
        }
        return 'clean';
    }

    public static function level_labels(): array {
        return [
            'suspicious'  => __('Suspicious', 'clickwarden'),
            'watch'       => __('Watch', 'clickwarden'),
            'clean'       => __('Clean', 'clickwarden'),
            'whitelisted' => __('Whitelisted', 'clickwarden'),
        ];
    }

    public static function describe_reasons(string $codes): array {
        $labels = self::reason_labels();
        $out = [];
        foreach (array_filter(explode(',', $codes)) as $code) {
            $out[] = $labels[$code] ?? $code;
        }
        return $out;
    }
}
