=== ClickWarden – Click Fraud Protection for Google Ads ===
Contributors: webmarka
Tags: click fraud, google ads, invalid clicks, ppc protection, ip exclusion
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect invalid Google Ads clicks on your own server and exclude fraudulent IPs from your campaigns automatically. Free, self-hosted, cache friendly.

== Description ==

Competitors, click farms and bots can burn through your Google Ads budget in hours. ClickWarden watches every ad click that lands on your site, scores each visitor for fraud and keeps the IP exclusion list of your Google Ads campaigns up to date – every hour, automatically.

Everything runs on your own WordPress site. There is no subscription and no account, and your visitor data is not sent to a third-party SaaS.

= How it works =

1. A small script (a few KB, no dependencies) records each page view, including the Google Ads click id (gclid, gbraid, wbraid), campaign id and UTM parameters. It runs in the browser, so it keeps working behind page caches and CDNs.
2. Every visitor IP gets a risk score from 0 to 100, based on:
   * repeated ad clicks from the same IP **or the same browser** (Google Analytics client id), even when the IP changes
   * datacenter, VPN, proxy and Tor networks (via ipapi.is or proxycheck.io, your choice)
   * automated and headless browsers (WebDriver, Puppeteer, Playwright, curl, …)
   * ad clicks without any interaction or time on page
   * clicks from outside your target countries
   * request floods
3. Suspicious IPs that clicked your ads go on an exclusion list. A ready-made Google Ads script syncs that list into the campaigns you choose once an hour.

= Features =

* Works with page caches and CDNs (LiteSpeed Cache, WP Rocket, Autoptimize, SiteGround Optimizer, host CDNs)
* Page reloads with the same click id are not counted twice – just like Google Ads
* Reads existing Google Analytics and Google Ads cookies (_ga, _gcl_au, _gcl_aw, _gcl_gb, _gcl_gs) to recognize returning ad clickers across IP addresses. ClickWarden never sets cookies itself.
* Bot detection for search engines, AI crawlers, SEO tools, uptime monitors and automation tools; Google's own AdsBot is recognized and never flagged
* Google Ads sync keeps your manual IP exclusions untouched and respects the 500 IPs per campaign limit
* Filterable visit and IP reports, CSV export, whitelist and manual flagging
* Setup checklist, dashboard with daily suspicious clicks and campaigns under attack
* Automatic data cleanup after a configurable retention period
* Privacy policy text for your site, fully translatable (English and Turkish included)

= Made by Webmarka =

ClickWarden is built and maintained by [Webmarka](https://webmarka.com), a digital agency. We use it on our own client campaigns and share it free of charge.

The full source code is available on [GitHub](https://github.com/hakanispirli/clickwarden). Bug reports and pull requests are welcome.

== Installation ==

1. Install and activate the plugin.
2. **Purge your page cache and CDN once**, so cached pages include the tracker.
3. Go to **ClickWarden → Settings**: add your own IP to "Excluded IPs", set your target countries, enable network lookups and choose a provider (ipapi.is or proxycheck.io).
4. Go to **ClickWarden → Google Ads**: enter the exact names of the campaigns to protect, copy the script and add it in Google Ads under Tools → Bulk actions → Scripts. Run "Preview" first, then schedule it hourly.

== Frequently Asked Questions ==

= Does blocking an IP on my website stop the click charge? =

No. Google charges the click before the visitor reaches your site. That is why ClickWarden does not block visitors on your site; it adds fraudulent IPs to your campaign IP exclusions in Google Ads, so your ads are no longer shown to them.

= Will real customers be blocked? =

A single signal is never enough to reach the default "suspicious" score of 60. Mobile carriers often share one IP among many people, so repeated clicks alone only put an IP on the watch list. You can whitelist any IP, and whitelisted IPs are removed from Google Ads on the next sync.

= Why do I see fewer ad clicks than in Google Ads? =

Bots that do not run JavaScript never load the tracker. A large gap between the two numbers is itself a sign of invalid traffic. Google also filters part of the invalid clicks itself; see the "Invalid clicks" column in Google Ads.

= No visits are recorded. What should I do? =

Your pages are probably served from a page cache or CDN created before ClickWarden was installed. Purge all caches (plugin and hosting/CDN). If you use a JavaScript optimization plugin, exclude `clickwarden/assets/js/tracker.js` from combining and delaying.

= Does it work with Performance Max campaigns? =

The script manages campaign-level IP exclusions. They apply to the whole campaign, including all ad groups. Google Ads decides which campaign types accept IP exclusions; campaigns that do not are reported on the Google Ads tab.

== External services ==

ClickWarden connects to the following services. Nothing is sent to any of them until you enable or set it up.

= IP lookup provider: ipapi.is or proxycheck.io =

Used to look up the country, city, network provider and network type (datacenter, VPN, proxy, Tor) of visitor IP addresses. You choose one of the two providers in the settings; only the selected one is contacted, and only when "Look up visitor IP addresses" is enabled. Data sent: the visitor IP address and, if configured, your API key for that provider. Lookups run in the background, at most once every 30 days per IP.

ipapi.is – Terms of service: https://ipapi.is/terms.html – Privacy policy: https://ipapi.is/privacy.html
proxycheck.io – Terms of service: https://proxycheck.io/terms – Privacy policy: https://proxycheck.io/privacy

= ip-api.com =

Optional fallback when the selected provider is unavailable. Only used when "Use ip-api.com …" is enabled. Data sent: the visitor IP address and, if configured, your ip-api.com Pro key. The free endpoint is for non-commercial use only.
Terms: https://ip-api.com/docs/legal – Privacy policy: https://ip-api.com/docs/legal

= Google Ads =

ClickWarden itself does not connect to Google. The optional Google Ads script you install in your own Google Ads account calls your WordPress site (secured by a secret key) to download the IP exclusion list, and reports back which IPs it added or removed.
Google Ads Scripts: https://developers.google.com/google-ads/scripts – Google privacy policy: https://policies.google.com/privacy

== Privacy ==

ClickWarden stores visitor IP addresses, user agents, visited paths, referrers, ad click identifiers and identifiers from existing Google cookies in your WordPress database, to detect ad fraud. Data older than the retention period (30 days by default) is deleted automatically. A suggested privacy policy text is added to Settings → Privacy. Logged-in editors and administrators are never tracked.

== Screenshots ==

1. Overview: ad clicks, suspicious share, riskiest IPs and campaigns under attack.
2. Visits: every page view with click id, campaign, browser id, network and engagement.
3. IP addresses: risk score with reasons and the Google Ads exclusion list.
4. Google Ads: the ready-to-paste sync script and sync status.
5. Settings: scoring thresholds, excluded IPs and network lookups.

== Changelog ==

= 3.0.0 =
* New name: ClickWarden – Click Fraud Protection for Google Ads.
* Google Analytics and Google Ads cookies (_ga, _gcl_au, _gcl_aw, _gcl_gb, _gcl_gs) and gad_source / gad_campaignid parameters.
* Detects the same browser clicking ads from several IP addresses.
* Page reloads with the same click id are no longer counted as new ad clicks.
* Network lookups are opt-in, with a choice of provider: ipapi.is or proxycheck.io. The ip-api.com fallback is optional.
* Setup checklist, tracking health warning, redesigned admin.
* Automatic exclusions for WP Rocket, Autoptimize and SiteGround Optimizer.
* Existing data from "Visitor Tracker" 2.x is imported automatically.

= 2.1.0 =
* Google Ads IP exclusion sync script.

= 2.0.0 =
* Browser-based tracking, risk scoring, IP exclusion export.
