<?php

if (!defined('ABSPATH')) {
    exit;
}

class ClickWarden_Bot_Detector {
    /**
     * Ordered by priority: the first matching category wins
     * (e.g. "AdsBot-Google" must be classified as ads, not search).
     * Keys are regex fragments matched case-insensitively.
     */
    private const RULES = [
        'ads' => [
            'adsbot-google'        => 'AdsBot-Google',
            'mediapartners-google' => 'Google AdSense',
            'google-adwords'       => 'Google Ads',
            'adidxbot'             => 'Bing AdIdxBot',
        ],
        'ai' => [
            'gptbot'             => 'GPTBot',
            'oai-searchbot'      => 'OpenAI SearchBot',
            'chatgpt-user'       => 'ChatGPT User',
            'claudebot'          => 'ClaudeBot',
            'claude-(?:web|user)' => 'Claude',
            'anthropic-ai'       => 'Anthropic',
            'perplexity'         => 'Perplexity',
            'ccbot'              => 'Common Crawl',
            'bytespider'         => 'Bytespider',
            'amazonbot'          => 'Amazonbot',
            'meta-externalagent' => 'Meta AI',
            'cohere-ai'          => 'Cohere',
            'diffbot'            => 'Diffbot',
            'youbot'             => 'YouBot',
            'applebot-extended'  => 'Applebot Extended',
        ],
        'search' => [
            'googlebot'              => 'Googlebot',
            'google-inspectiontool'  => 'Google Inspection Tool',
            'googleother'            => 'GoogleOther',
            'storebot-google'        => 'Google StoreBot',
            'bingbot'                => 'Bingbot',
            'bingpreview'            => 'Bing Preview',
            'yandex(?:bot|images|mobilebot)' => 'YandexBot',
            'duckduckbot'            => 'DuckDuckBot',
            'baiduspider'            => 'Baidu Spider',
            'applebot'               => 'Applebot',
            'slurp'                  => 'Yahoo Slurp',
            'petalbot'               => 'PetalBot',
            'seznambot'              => 'SeznamBot',
            'sogou'                  => 'Sogou',
        ],
        'seo' => [
            'ahrefs'            => 'Ahrefs',
            'semrush'           => 'Semrush',
            'mj12bot'           => 'Majestic',
            'dotbot'            => 'DotBot',
            'rogerbot'          => 'Moz',
            'screaming frog'    => 'Screaming Frog',
            'siteauditbot'      => 'SiteAuditBot',
            'serpstatbot'       => 'Serpstat',
            'dataforseobot'     => 'DataForSEO',
            'blexbot'           => 'BLEXBot',
            'barkrowler'        => 'Babbar',
        ],
        'social' => [
            'facebookexternalhit' => 'Facebook',
            'facebookcatalog'     => 'Facebook Catalog',
            'meta-externalfetcher' => 'Meta Fetcher',
            'twitterbot'          => 'X (Twitter)',
            'linkedinbot'         => 'LinkedIn',
            'pinterestbot'        => 'Pinterest',
            'whatsapp'            => 'WhatsApp',
            'telegrambot'         => 'Telegram',
            'slackbot'            => 'Slack',
            'discordbot'          => 'Discord',
            'skypeuripreview'     => 'Skype',
        ],
        'monitor' => [
            'uptimerobot'       => 'UptimeRobot',
            'pingdom'           => 'Pingdom',
            'statuscake'        => 'StatusCake',
            'site24x7'          => 'Site24x7',
            'betteruptime'      => 'Better Uptime',
            'gtmetrix'          => 'GTmetrix',
            'chrome-lighthouse' => 'Lighthouse',
            'pagespeed'         => 'PageSpeed',
        ],
        'tool' => [
            'headlesschrome'   => 'Headless Chrome',
            'phantomjs'        => 'PhantomJS',
            'puppeteer'        => 'Puppeteer',
            'playwright'       => 'Playwright',
            'selenium'         => 'Selenium',
            'webdriver'        => 'WebDriver',
            'curl\/'           => 'curl',
            'wget'             => 'Wget',
            'python-requests'  => 'Python Requests',
            'python-urllib'    => 'Python urllib',
            'python-httpx'     => 'Python httpx',
            'aiohttp'          => 'aiohttp',
            'go-http-client'   => 'Go HTTP',
            '^java\/'          => 'Java',
            'okhttp'           => 'OkHttp',
            'axios'            => 'Axios',
            'node-fetch'       => 'node-fetch',
            'undici'           => 'Node undici',
            'scrapy'           => 'Scrapy',
            'libwww-perl'      => 'Perl LWP',
            'guzzlehttp'       => 'Guzzle',
            'postmanruntime'   => 'Postman',
            'httpclient'       => 'HTTP Client',
        ],
    ];

    /**
     * @return array{is_bot: bool, category: string, name: string}
     */
    public static function detect(?string $user_agent): array {
        $user_agent = trim((string) $user_agent);

        if (strlen($user_agent) < 10) {
            return ['is_bot' => true, 'category' => 'tool', 'name' => 'Empty user agent'];
        }

        foreach (self::RULES as $category => $patterns) {
            foreach ($patterns as $pattern => $name) {
                if (preg_match('/' . $pattern . '/i', $user_agent)) {
                    return ['is_bot' => true, 'category' => $category, 'name' => $name];
                }
            }
        }

        // Generic markers as whole words only ("Cubot" is a phone brand, not a bot).
        if (preg_match('/(bot|crawler|spider|scraper)\b/i', $user_agent) && !preg_match('/\bcubot\b/i', $user_agent)) {
            return ['is_bot' => true, 'category' => 'generic', 'name' => 'Unknown bot'];
        }

        return ['is_bot' => false, 'category' => '', 'name' => ''];
    }

    public static function categories(): array {
        return [
            'ads'     => __('Google/Bing ad bots', 'clickwarden'),
            'search'  => __('Search engines', 'clickwarden'),
            'ai'      => __('AI crawlers', 'clickwarden'),
            'seo'     => __('SEO tools', 'clickwarden'),
            'social'  => __('Social previews', 'clickwarden'),
            'monitor' => __('Uptime / performance', 'clickwarden'),
            'tool'    => __('Automation / headless', 'clickwarden'),
            'generic' => __('Other bots', 'clickwarden'),
        ];
    }
}
