/*! ClickWarden – cache-friendly click beacon */
(() => {
    'use strict';

    const script = document.currentScript;
    const endpoint = script && script.dataset.cwEndpoint;
    if (!endpoint || !window.fetch || !window.URLSearchParams) {
        return;
    }

    const PARAMS = ['gclid', 'gbraid', 'wbraid', 'gad_source', 'gad_campaignid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term'];

    const url = (route) => {
        // Works for both /wp-json/clickwarden/v1/ and ?rest_route=/clickwarden/v1/ permalink styles.
        return endpoint.includes('?') ? endpoint.replace(/(rest_route=[^&]*)/, `$1${route}`) : endpoint + route;
    };

    const uuid = () => {
        if (window.crypto && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };

    const cookie = (name) => {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    };

    /**
     * Reads identifiers the Google tag already stored (nothing is written):
     *  _ga      GA1.1.<random>.<first visit>  -> browser id shared by all visits
     *  _gcl_aw  GCL.<time>.<gclid>            -> last Google Ads click of this browser
     *  _gcl_gb  GCL.<time>.<gbraid>           -> last iOS app-to-web ad click
     *  _gcl_au  1.1.<random>.<time>           -> Google Ads conversion linker id
     *  _gcl_gs  opaque                        -> Google Ads click/session data
     */
    const googleCookies = () => {
        const ga = cookie('_ga').split('.');
        const gclValue = (raw) => raw.split('.').slice(2).join('.');
        return {
            ga: ga.length >= 4 ? ga.slice(-2).join('.') : '',
            gcl_au: cookie('_gcl_au'),
            gcl_aw: gclValue(cookie('_gcl_aw')),
            gcl_gb: gclValue(cookie('_gcl_gb')),
            gcl_gs: cookie('_gcl_gs'),
        };
    };

    // Form encoding is CORS-safelisted, so sendBeacon accepts it in every browser.
    const post = (route, data, useBeacon) => {
        const body = new URLSearchParams();
        Object.keys(data).forEach((key) => {
            const value = data[key];
            if (value === '' || value === null || value === undefined) {
                return;
            }
            body.append(key, value === true ? '1' : value === false ? '0' : String(value).slice(0, 500));
        });

        if (useBeacon && navigator.sendBeacon && navigator.sendBeacon(url(route), body)) {
            return;
        }
        fetch(url(route), {
            method: 'POST',
            body,
            keepalive: true,
            credentials: 'same-origin',
        }).catch(() => {});
    };

    const start = () => {
        const uid = uuid();
        const query = new URLSearchParams(location.search);
        const hit = Object.assign({
            uid,
            path: location.pathname,
            ref: document.referrer,
            wd: navigator.webdriver === true,
        }, googleCookies());
        PARAMS.forEach((key) => {
            hit[key] = query.get(key) || '';
        });

        post('hit', hit, false);

        let visibleMs = 0;
        let visibleSince = document.visibilityState === 'visible' ? performance.now() : null;
        let interacted = false;
        let maxScroll = 0;
        let lastSent = -1;

        const markInteraction = () => {
            interacted = true;
        };
        ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'wheel'].forEach((type) => {
            window.addEventListener(type, markInteraction, { passive: true, once: true });
        });

        window.addEventListener('scroll', () => {
            const scrollable = document.documentElement.scrollHeight - window.innerHeight;
            const pct = scrollable > 0 ? Math.round((window.scrollY / scrollable) * 100) : 100;
            maxScroll = Math.max(maxScroll, Math.min(100, pct));
            if (window.scrollY > 0) {
                interacted = true;
            }
        }, { passive: true });

        const flush = () => {
            if (visibleSince !== null) {
                visibleMs += performance.now() - visibleSince;
                visibleSince = null;
            }
            const ms = Math.round(visibleMs);
            if (ms === lastSent) {
                return;
            }
            lastSent = ms;
            // Cookies are re-read here: the Google tag usually sets them after this script ran.
            post('engage', Object.assign({ uid, ms, i: interacted, s: maxScroll }, googleCookies()), true);
        };

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                flush();
            } else if (visibleSince === null) {
                visibleSince = performance.now();
            }
        });
        window.addEventListener('pagehide', flush);
    };

    // Do not count speculative prerenders until the visitor actually sees the page.
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', start, { once: true });
    } else {
        start();
    }
})();
