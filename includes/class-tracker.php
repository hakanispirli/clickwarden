<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracking runs in the browser (tracker.js -> REST) so it keeps working when
 * LiteSpeed Cache serves the page from cache without executing PHP.
 */
class ClickWarden_Tracker {
    private const HANDLE = 'clickwarden';

    public function register(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('script_loader_tag', [$this, 'script_tag'], 10, 2);

        // Keep optimization plugins from combining, deferring or delaying the tracker.
        foreach (['litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc',
                  'rocket_exclude_js', 'rocket_exclude_defer_js', 'rocket_delay_js_exclusions'] as $filter) {
            add_filter($filter, [$this, 'exclude_path']);
        }
        foreach (['sgo_javascript_combine_exclude', 'sgo_js_minify_exclude', 'sgo_js_async_exclude'] as $filter) {
            add_filter($filter, [$this, 'exclude_handle']);
        }
        add_filter('autoptimize_filter_js_exclude', [$this, 'autoptimize_exclude']);
    }

    private function should_track(): bool {
        if (is_admin() || is_preview() || is_customize_preview() || wp_doing_ajax()) {
            return false;
        }
        // Logged-in staff get their own (uncached) pages from LiteSpeed, so this is reliable.
        if (current_user_can('edit_posts')) {
            return false;
        }
        return (bool) apply_filters('clickwarden_should_track', true);
    }

    public function enqueue(): void {
        if (!$this->should_track()) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            CLICKWARDEN_PLUGIN_URL . 'assets/js/tracker.js',
            [],
            CLICKWARDEN_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );
    }

    public function script_tag(string $tag, string $handle): string {
        if (self::HANDLE !== $handle) {
            return $tag;
        }

        $attributes = sprintf(
            ' data-cw-endpoint="%s" data-no-optimize="1" data-no-defer="1" data-cfasync="false"',
            esc_url(rest_url('clickwarden/v1/'))
        );

        return preg_replace('/<script\b/', '<script' . $attributes, $tag, 1);
    }

    public function exclude_path($excludes): array {
        $excludes = is_array($excludes) ? $excludes : [];
        $excludes[] = 'clickwarden/assets/js/tracker.js';
        return $excludes;
    }

    public function exclude_handle($handles): array {
        $handles = is_array($handles) ? $handles : [];
        $handles[] = self::HANDLE;
        return $handles;
    }

    public function autoptimize_exclude($excludes): string {
        return trim((string) $excludes . ', clickwarden/assets/js/tracker.js', ', ');
    }
}
