<?php

namespace App\Support\Plugins;

use Illuminate\Validation\ValidationException;

/**
 * Turns resolved plugin config into a portable, renderable tag list.
 *
 * The output is data rather than HTML on purpose. Two runtimes have to consume
 * it — the storefront SPA in the browser, and the Cloudflare Worker that
 * rewrites the raw HTML so verification meta tags are visible to crawlers that
 * do not run JavaScript — and neither can render a PHP string.
 *
 * ## Interpolation safety
 *
 * Every `{placeholder}` is substituted with a value that has just been through
 * {@see PluginRegistry::validatedConfig()}, i.e. one that matched its field's
 * allow-list pattern (digits, `AW-\d+`, and so on). The substitution is a plain
 * `str_replace` by design: escaping would be the wrong tool, because the
 * guarantee we rely on is that the value cannot contain a quote in the first
 * place. A config that no longer passes validation is dropped, not emitted —
 * otherwise a row written before a rule tightened would keep leaking.
 */
final class PluginTagRenderer
{
    /**
     * @param  array<string, array<string, mixed>>  $plugins  keyed by plugin key; only enabled ones
     * @return array{scripts: array<int, array<string, mixed>>, inline: array<int, array<string, mixed>>, metas: array<int, array<string, mixed>>, body: array<int, array<string, mixed>>, widgets: array<int, array<string, mixed>>, plugins: array<int, string>}
     */
    public static function render(array $plugins): array
    {
        $scripts = [];
        $inline = [];
        $metas = [];
        $body = [];
        $widgets = [];
        $active = [];

        /** @var array<int, string> $gtagIds */
        $gtagIds = [];

        foreach ($plugins as $key => $config) {
            if (! PluginRegistry::has($key)) {
                continue;
            }

            try {
                $values = PluginRegistry::validatedConfig($key, (array) $config);
            } catch (ValidationException) {
                // Stored config that no longer satisfies its rules is skipped
                // rather than emitted. Tightening a regex must not leave the
                // old value running on live storefronts.
                continue;
            }

            $plugin = PluginRegistry::get($key);
            $active[] = $key;

            if (($plugin['merge_group'] ?? null) === PluginRegistry::MERGE_GROUP_GTAG) {
                $id = $values[$plugin['merge_field']] ?? null;
                if ($id !== null) {
                    $gtagIds[] = $id;
                }
            } else {
                foreach ($plugin['head']['scripts'] as $script) {
                    $scripts[] = ['id' => $key] + $script;
                }

                foreach ($plugin['head']['inline'] as $template) {
                    $inline[] = ['id' => $key, 'js' => self::substitute($template, $values)];
                }
            }

            foreach ($plugin['head']['metas'] as $meta) {
                $metas[] = [
                    'id' => $key,
                    'name' => $meta['name'],
                    'content' => self::substitute($meta['content'], $values),
                ];
            }

            foreach ($plugin['body']['html'] as $html) {
                $body[] = ['id' => $key, 'html' => self::substitute($html, $values)];
            }

            if (isset($plugin['widget'])) {
                $widgetConfig = [];
                foreach ($plugin['widget_fields'] ?? [] as $field) {
                    if (isset($values[$field])) {
                        $widgetConfig[$field] = $values[$field];
                    }
                }

                $widgets[] = [
                    'key' => $plugin['widget'],
                    'plugin' => $key,
                    'config' => $widgetConfig,
                ];
            }
        }

        if ($gtagIds !== []) {
            // One loader and one bootstrap for every Google tag, which is
            // Google's own guidance: two gtag.js loads for two ids is how a
            // page_view gets counted twice. The merged pair shares one id so
            // the browser-side injector can recognise the group as a unit.
            $scripts[] = [
                'id' => 'gtag',
                'src' => PluginRegistry::GTAG_LOADER.rawurlencode($gtagIds[0]),
                'async' => true,
            ];

            $configs = implode('', array_map(
                static fn (string $id): string => "gtag('config','".$id."');",
                $gtagIds
            ));

            $inline[] = [
                'id' => 'gtag',
                'js' => self::substitute(
                    PluginRegistry::JS_GTAG_TEMPLATE,
                    ['configs' => $configs]
                ),
            ];
        }

        return [
            'scripts' => $scripts,
            'inline' => $inline,
            'metas' => $metas,
            'body' => $body,
            'widgets' => $widgets,
            'plugins' => $active,
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function substitute(string $template, array $values): string
    {
        if ($values === [] || ! str_contains($template, '{')) {
            return $template;
        }

        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
