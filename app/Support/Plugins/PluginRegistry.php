<?php

namespace App\Support\Plugins;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The catalogue of connectable third-party services.
 *
 * The plugin is ours; only the values are the business's. That is the whole
 * point of this class — a business never pastes a script, it supplies an ID,
 * and the snippet comes from here. Adding a service is one entry in
 * {@see self::definitions()} and no migration at all.
 *
 * ## Why every field carries a regex
 *
 * Field values are substituted into `<script>` tags that run on every page of a
 * live storefront. Interpolating raw request input there would be stored XSS
 * with a very short path to exploitation. So every field declares an allow-list
 * pattern, {@see self::validatedConfig()} refuses anything that fails, and the
 * renderer substitutes only values that have already passed. A value that has
 * matched `^\d{15,16}$` cannot close a quote, and that is the actual defence.
 *
 * Fields holding free text (the WhatsApp greeting) are the exception, and are
 * safe for one reason: they are never interpolated into a script. They reach
 * the browser only through `widget_fields`, where the storefront renders them
 * as text content.
 */
final class PluginRegistry
{
    public const CATEGORY_ADS = 'ads';

    public const CATEGORY_ANALYTICS = 'analytics';

    public const CATEGORY_VERIFICATION = 'verification';

    public const CATEGORY_CHAT = 'chat';

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        self::CATEGORY_ADS => 'Ads & pixels',
        self::CATEGORY_ANALYTICS => 'Analytics',
        self::CATEGORY_VERIFICATION => 'Verification',
        self::CATEGORY_CHAT => 'Chat & support',
    ];

    /*
    |--------------------------------------------------------------------------
    | Inline snippets
    |--------------------------------------------------------------------------
    | Provider-issued base code, kept verbatim. These are nowdocs so PHP never
    | interpolates anything inside them and `{placeholder}` survives literally
    | for the renderer to substitute.
    */

    private const JS_META_PIXEL = <<<'JS'
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init','{pixel_id}');fbq('track','PageView');
    JS;

    private const JS_GTM = <<<'JS'
    (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
    var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
    j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
    f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{container_id}');
    JS;

    private const HTML_GTM_NOSCRIPT = <<<'HTML'
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={container_id}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    HTML;

    private const JS_TIKTOK_PIXEL = <<<'JS'
    !function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];
    ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"];
    ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};
    for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);
    ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};
    ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;
    ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};
    n=document.createElement("script");n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;
    e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e)};
    ttq.load('{pixel_id}');ttq.page();}(window,document,'ttq');
    JS;

    private const JS_PINTEREST_TAG = <<<'JS'
    !function(e){if(!window.pintrk){window.pintrk=function(){window.pintrk.queue.push(Array.prototype.slice.call(arguments))};
    var n=window.pintrk;n.queue=[],n.version="3.0";var t=document.createElement("script");t.async=!0,t.src=e;
    var r=document.getElementsByTagName("script")[0];r.parentNode.insertBefore(t,r)}}("https://s.pinimg.com/ct/core.js");
    pintrk('load','{tag_id}');pintrk('page');
    JS;

    private const JS_LINKEDIN_INSIGHT = <<<'JS'
    _linkedin_partner_id="{partner_id}";window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];
    window._linkedin_data_partner_ids.push(_linkedin_partner_id);
    (function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}
    var s=document.getElementsByTagName("script")[0];var b=document.createElement("script");
    b.type="text/javascript";b.async=true;b.src="https://snap.licdn.com/li.lms-analytics/insight.min.js";
    s.parentNode.insertBefore(b,s)})(window.lintrk);
    JS;

    private const HTML_LINKEDIN_NOSCRIPT = <<<'HTML'
    <noscript><img height="1" width="1" style="display:none;" alt="" src="https://px.ads.linkedin.com/collect/?pid={partner_id}&fmt=gif" /></noscript>
    HTML;

    private const JS_CLARITY = <<<'JS'
    (function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
    t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y)})(window,document,"clarity","script","{project_id}");
    JS;

    private const JS_CRISP = <<<'JS'
    window.$crisp=[];window.CRISP_WEBSITE_ID="{website_id}";
    (function(){var d=document,s=d.createElement("script");s.src="https://client.crisp.chat/l.js";s.async=1;
    d.getElementsByTagName("head")[0].appendChild(s);})();
    JS;

    /**
     * The Google tag loader, shared by GA4 and Google Ads.
     *
     * Both are configured through one gtag.js: Google's guidance is a single
     * loader carrying every ID, and loading the script twice for two IDs is how
     * you end up with a duplicated page_view. {@see PluginTagRenderer} collects
     * the ids from every plugin declaring `merge_group: gtag` and emits this
     * once with all of them.
     */
    public const JS_GTAG_TEMPLATE = <<<'JS'
    window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
    gtag('js',new Date());{configs}
    JS;

    public const GTAG_LOADER = 'https://www.googletagmanager.com/gtag/js?id=';

    public const MERGE_GROUP_GTAG = 'gtag';

    /**
     * The full catalogue.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(): array
    {
        return [
            'google_ads' => [
                'name' => 'Google Ads',
                'category' => self::CATEGORY_ADS,
                'description' => 'Record conversions from your Google Ads campaigns.',
                'icon' => 'fi fi-rr-megaphone',
                'docs_url' => 'https://ads.google.com/aw/conversions',
                'fields' => [
                    [
                        'key' => 'conversion_id',
                        'label' => 'Conversion ID',
                        'help' => 'Google Ads → Goals → Conversions → your action → Tag setup',
                        'placeholder' => 'AW-123456789',
                        'rules' => ['required', 'string', 'regex:/^AW-\d{9,11}$/'],
                    ],
                    // A conversion *label* deliberately has no field yet. It is
                    // only meaningful alongside a conversion event, and v1 ships
                    // page-level tags only — storing a value nothing reads would
                    // be worse than not asking for it.
                ],
                'merge_group' => self::MERGE_GROUP_GTAG,
                'merge_field' => 'conversion_id',
                'head' => ['scripts' => [], 'inline' => [], 'metas' => []],
                'body' => ['html' => []],
            ],

            'meta_pixel' => [
                'name' => 'Meta Pixel',
                'category' => self::CATEGORY_ADS,
                'description' => 'Track conversions and build audiences from Facebook and Instagram ads.',
                'icon' => 'fi fi-rr-social-network',
                'docs_url' => 'https://business.facebook.com/events_manager',
                'fields' => [
                    [
                        'key' => 'pixel_id',
                        'label' => 'Pixel ID',
                        'help' => 'Events Manager → Data Sources → your pixel',
                        'placeholder' => '123456789012345',
                        'rules' => ['required', 'string', 'regex:/^\d{15,16}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [self::JS_META_PIXEL], 'metas' => []],
                'body' => ['html' => []],
            ],

            'tiktok_pixel' => [
                'name' => 'TikTok Pixel',
                'category' => self::CATEGORY_ADS,
                'description' => 'Measure TikTok ad performance and retarget visitors.',
                'icon' => 'fi fi-rr-play-alt',
                'docs_url' => 'https://ads.tiktok.com/i18n/events_manager',
                'fields' => [
                    [
                        'key' => 'pixel_id',
                        'label' => 'Pixel ID',
                        'help' => 'TikTok Ads Manager → Assets → Events',
                        'placeholder' => 'C1AB2CD3EF4GH5IJ6KL7',
                        'rules' => ['required', 'string', 'regex:/^[A-Z0-9]{18,24}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [self::JS_TIKTOK_PIXEL], 'metas' => []],
                'body' => ['html' => []],
            ],

            'pinterest_tag' => [
                'name' => 'Pinterest Tag',
                'category' => self::CATEGORY_ADS,
                'description' => 'Track conversions and build audiences from Pinterest.',
                // Not the social-network glyph Meta uses — two plugins sharing
                // one icon makes the grid harder to scan than it needs to be.
                'icon' => 'fi fi-rr-bookmark',
                'docs_url' => 'https://ads.pinterest.com/',
                'fields' => [
                    [
                        'key' => 'tag_id',
                        'label' => 'Tag ID',
                        'help' => 'Pinterest Ads → Conversions → your tag',
                        'placeholder' => '1234567890123',
                        'rules' => ['required', 'string', 'regex:/^\d{13}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [self::JS_PINTEREST_TAG], 'metas' => []],
                'body' => ['html' => []],
            ],

            'linkedin_insight' => [
                'name' => 'LinkedIn Insight Tag',
                'category' => self::CATEGORY_ADS,
                'description' => 'Measure LinkedIn campaign performance and retarget visitors.',
                'icon' => 'fi fi-rr-briefcase',
                'docs_url' => 'https://www.linkedin.com/campaignmanager/',
                'fields' => [
                    [
                        'key' => 'partner_id',
                        'label' => 'Partner ID',
                        'help' => 'Campaign Manager → Account assets → Insight Tag',
                        'placeholder' => '1234567',
                        'rules' => ['required', 'string', 'regex:/^\d{5,8}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [self::JS_LINKEDIN_INSIGHT], 'metas' => []],
                'body' => ['html' => [self::HTML_LINKEDIN_NOSCRIPT]],
            ],

            'ga4' => [
                'name' => 'Google Analytics 4',
                'category' => self::CATEGORY_ANALYTICS,
                'description' => 'Measure traffic, funnels and revenue in Google Analytics.',
                'icon' => 'fi fi-rr-chart-histogram',
                'docs_url' => 'https://analytics.google.com/',
                'fields' => [
                    [
                        'key' => 'measurement_id',
                        'label' => 'Measurement ID',
                        'help' => 'GA4 → Admin → Data streams → your web stream',
                        'placeholder' => 'G-ABCDE12345',
                        'rules' => ['required', 'string', 'regex:/^G-[A-Z0-9]{6,14}$/'],
                    ],
                ],
                'merge_group' => self::MERGE_GROUP_GTAG,
                'merge_field' => 'measurement_id',
                'head' => ['scripts' => [], 'inline' => [], 'metas' => []],
                'body' => ['html' => []],
            ],

            'gtm' => [
                'name' => 'Google Tag Manager',
                'category' => self::CATEGORY_ANALYTICS,
                'description' => 'Load and manage tags from a GTM container instead of connecting them one by one.',
                'icon' => 'fi fi-rr-settings-sliders',
                'docs_url' => 'https://tagmanager.google.com/',
                'fields' => [
                    [
                        'key' => 'container_id',
                        'label' => 'Container ID',
                        'help' => 'Tag Manager → your container (top right)',
                        'placeholder' => 'GTM-ABC1234',
                        'rules' => ['required', 'string', 'regex:/^GTM-[A-Z0-9]{4,9}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [self::JS_GTM], 'metas' => []],
                'body' => ['html' => [self::HTML_GTM_NOSCRIPT]],
            ],

            'clarity' => [
                'name' => 'Microsoft Clarity',
                'category' => self::CATEGORY_ANALYTICS,
                'description' => 'Session recordings and heatmaps, free from Microsoft.',
                'icon' => 'fi fi-rr-eye',
                'docs_url' => 'https://clarity.microsoft.com/',
                'fields' => [
                    [
                        'key' => 'project_id',
                        'label' => 'Project ID',
                        'help' => 'Clarity → Settings → Overview',
                        'placeholder' => 'abcdefghij',
                        'rules' => ['required', 'string', 'regex:/^[a-z0-9]{8,12}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [self::JS_CLARITY], 'metas' => []],
                'body' => ['html' => []],
            ],

            'google_search_console' => [
                'name' => 'Google Search Console',
                'category' => self::CATEGORY_VERIFICATION,
                'description' => 'Prove you own this domain so Google will report on it.',
                'icon' => 'fi fi-rr-search',
                'docs_url' => 'https://search.google.com/search-console',
                'raw_html_required' => true,
                'fields' => [
                    [
                        'key' => 'token',
                        'label' => 'Verification token',
                        'help' => 'Search Console → Add property → HTML tag → copy only the content value.',
                        'placeholder' => 'nxGUDJ4QpAZ5l9Bsjdi102tLVC21AIh5d1Nl23908vVuFHs34=',
                        'rules' => ['required', 'string', 'regex:/^[A-Za-z0-9_+=\/-]{20,80}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [], 'metas' => [
                    ['name' => 'google-site-verification', 'content' => '{token}'],
                ]],
                'body' => ['html' => []],
            ],

            'bing_webmaster' => [
                'name' => 'Bing Webmaster Tools',
                'category' => self::CATEGORY_VERIFICATION,
                'description' => 'Verify the domain with Bing so it can be indexed and reported on.',
                'icon' => 'fi fi-rr-globe',
                'docs_url' => 'https://www.bing.com/webmasters',
                'raw_html_required' => true,
                'fields' => [
                    [
                        'key' => 'token',
                        'label' => 'Verification token',
                        'help' => 'Bing Webmaster Tools → Add site → HTML Meta Tag.',
                        'placeholder' => '0123456789ABCDEF0123456789ABCDEF',
                        'rules' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [], 'metas' => [
                    ['name' => 'msvalidate.01', 'content' => '{token}'],
                ]],
                'body' => ['html' => []],
            ],

            'meta_domain' => [
                'name' => 'Meta Domain Verification',
                'category' => self::CATEGORY_VERIFICATION,
                'description' => 'Claim this domain in Meta Business Manager.',
                'icon' => 'fi fi-rr-shield-check',
                'docs_url' => 'https://business.facebook.com/settings/owned-domains',
                'raw_html_required' => true,
                'fields' => [
                    [
                        'key' => 'token',
                        'label' => 'Verification token',
                        'help' => 'Business Settings → Brand safety → Domains → Meta-tag verification.',
                        'placeholder' => 'abcdefghijklmnopqrstuvwxyz123456',
                        'rules' => ['required', 'string', 'regex:/^[a-z0-9]{16,40}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [], 'metas' => [
                    ['name' => 'facebook-domain-verification', 'content' => '{token}'],
                ]],
                'body' => ['html' => []],
            ],

            'crisp' => [
                'name' => 'Crisp live chat',
                'category' => self::CATEGORY_CHAT,
                'description' => 'Answer customer questions from a live chat box on your storefront.',
                'icon' => 'fi fi-rr-comment-alt',
                'docs_url' => 'https://app.crisp.chat/',
                'fields' => [
                    [
                        'key' => 'website_id',
                        'label' => 'Website ID',
                        'help' => 'Crisp → Settings → Website settings → Setup instructions.',
                        'placeholder' => '1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d',
                        'rules' => ['required', 'string', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/'],
                    ],
                ],
                'head' => ['scripts' => [], 'inline' => [self::JS_CRISP], 'metas' => []],
                'body' => ['html' => []],
            ],

            'whatsapp' => [
                'name' => 'WhatsApp',
                'category' => self::CATEGORY_CHAT,
                'description' => 'A floating button that opens a WhatsApp chat with your business.',
                'icon' => 'fi fi-rr-phone-call',
                'docs_url' => 'https://faq.whatsapp.com/425247423114725',
                // Rendered by the storefront as a widget rather than injected as
                // a script — the message is free text, and free text never goes
                // anywhere near a tag template.
                'widget' => 'whatsapp',
                'fields' => [
                    [
                        'key' => 'phone',
                        'label' => 'WhatsApp number',
                        'help' => 'Include the country code, e.g. 2348012345678.',
                        'placeholder' => '2348012345678',
                        'rules' => ['required', 'string', 'regex:/^\d{8,15}$/'],
                    ],
                    [
                        'key' => 'message',
                        'label' => 'Pre-filled message',
                        'help' => 'Optional. Appears in the chat when a customer taps the button.',
                        'placeholder' => 'Hello! I would like to ask about an order.',
                        'optional' => true,
                        'rules' => ['nullable', 'string', 'max:200'],
                    ],
                ],
                'widget_fields' => ['phone', 'message'],
                'head' => ['scripts' => [], 'inline' => [], 'metas' => []],
                'body' => ['html' => []],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        static $catalogue = null;

        return $catalogue ??= self::definitions();
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Validation rules for one plugin's config, keyed by field.
     *
     * Built from the same definition the UI renders from, so the two cannot
     * drift: a rule can only exist in one place.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(string $key): array
    {
        $plugin = self::get($key);

        if ($plugin === null) {
            return [];
        }

        $rules = [];
        foreach ($plugin['fields'] as $field) {
            $rules[$field['key']] = $field['rules'];
        }

        return $rules;
    }

    /**
     * The catalogue as the management UI needs it — no tag templates, since the
     * browser has no business rendering them and shipping them would leak the
     * shapes we validate against.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cataloguePayload(): array
    {
        $payload = [];

        foreach (self::all() as $key => $plugin) {
            $payload[] = [
                'key' => $key,
                'name' => $plugin['name'],
                'category' => $plugin['category'],
                'category_label' => self::CATEGORIES[$plugin['category']] ?? $plugin['category'],
                'description' => $plugin['description'],
                'icon' => $plugin['icon'],
                'docs_url' => $plugin['docs_url'],
                'raw_html_required' => (bool) ($plugin['raw_html_required'] ?? false),
                'fields' => array_map(static fn (array $field): array => [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'help' => $field['help'] ?? null,
                    'placeholder' => $field['placeholder'] ?? null,
                    'optional' => (bool) ($field['optional'] ?? false),
                ], $plugin['fields']),
            ];
        }

        return $payload;
    }

    /**
     * Failure messages that name the field the way the form does.
     *
     * Keyed by `field.rule` without the attribute prefix, so the form request
     * can nest them under `config.` and the validator here can use them flat —
     * one wording, both paths. Left to itself Laravel reports the attribute path
     * verbatim ("The config.pixel id field format is invalid"), which tells a
     * shopkeeper nothing about which box to look in.
     *
     * @return array<string, string>
     */
    public static function messagesFor(string $key): array
    {
        $messages = [];

        foreach (self::get($key)['fields'] ?? [] as $field) {
            $messages["{$field['key']}.required"] = "{$field['label']} is required.";
            $messages["{$field['key']}.regex"] = "That does not look like a valid {$field['label']}. Check it against the provider's dashboard and try again.";
            $messages["{$field['key']}.max"] = "{$field['label']} is longer than this field allows.";
        }

        return $messages;
    }

    /**
     * Validate a stored or submitted config against the plugin's field rules.
     *
     * Returns the values keyed by field, with unset optional fields dropped, or
     * throws. Everything downstream — the renderer, the public payload — may
     * then assume these strings are safe to interpolate.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function validatedConfig(string $key, array $config): array
    {
        $validated = Validator::make($config, self::rulesFor($key), self::messagesFor($key))->validate();

        $clean = [];
        foreach (self::get($key)['fields'] as $field) {
            $value = $validated[$field['key']] ?? null;

            if ($value === null || $value === '') {
                if (! ($field['optional'] ?? false)) {
                    throw ValidationException::withMessages([
                        $field['key'] => "The {$field['label']} field is required.",
                    ]);
                }

                continue;
            }

            $clean[$field['key']] = (string) $value;
        }

        return $clean;
    }
}
