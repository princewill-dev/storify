<?php

namespace App\Services\Management;

use App\Helpers\UrlHelper;
use App\Models\Store;
use App\Support\Plugins\PluginRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a plugin actually reached the storefront.
 *
 * It fetches the storefront with **no JavaScript** and looks for the plugin's
 * identifying value in the returned HTML. That is not a limitation — it is the
 * point. A plain HTTP fetch is exactly how Google's and Meta's verification
 * checks see the page, so a tag that is only added client-side will be absent
 * here, and the business learns that before the provider does.
 *
 * The URL is built by {@see UrlHelper::storeUrl()} from server configuration
 * plus the store's own slug. Nothing from the request reaches it — a
 * caller-supplied host here would be an SSRF primitive, so there is no
 * parameter for one.
 */
final class PluginVerifier
{
    /**
     * @param  array<string, mixed>  $config  resolved, enabled config for this store
     * @return array{reachable: bool, found: bool, status: int|null, url: string, message: string}
     */
    public function verify(Store $store, string $pluginKey, array $config): array
    {
        $plugin = PluginRegistry::get($pluginKey);
        $url = UrlHelper::storeUrl($store->slug);

        try {
            $values = PluginRegistry::validatedConfig($pluginKey, $config);
        } catch (ValidationException) {
            return [
                'reachable' => true,
                'found' => false,
                'status' => null,
                'url' => $url,
                'message' => 'This plugin is connected but its saved details no longer match the expected format, so nothing is being served. Save it again.',
            ];
        }

        // The first field is the identifying one for every plugin in the
        // catalogue — a pixel id, a container id, a verification token.
        $probe = $values[$plugin['fields'][0]['key']] ?? null;

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                ->withUserAgent('Storify-PluginCheck/1.0')
                ->get($url);
        } catch (ConnectionException) {
            return [
                'reachable' => false,
                'found' => false,
                'status' => null,
                'url' => $url,
                'message' => 'Could not reach your storefront to check. It may not be published yet.',
            ];
        }

        if (! $response->successful()) {
            return [
                'reachable' => false,
                'found' => false,
                'status' => $response->status(),
                'url' => $url,
                'message' => "Your storefront answered with {$response->status()}, so there was nothing to check.",
            ];
        }

        $found = $probe !== null && str_contains($response->body(), $probe);

        if ($found) {
            $message = $plugin['name'].' is live on your storefront. Providers reading the page can see it.';
        } elseif ($plugin['raw_html_required'] ?? false) {
            // These are read from the raw HTML by crawlers that do not run
            // JavaScript, so a client-side injection alone will never satisfy
            // them. Say that plainly rather than reporting a generic failure.
            $message = $plugin['name'].' is not in the page source yet. This one has to be served with the page itself, so it needs the storefront edge deployment — until that ships it will not be visible to verification checks.';
        } else {
            $message = $plugin['name'].' is not on your storefront yet. Reload your storefront to pick up the change, then check again.';
        }

        return [
            'reachable' => true,
            'found' => $found,
            'status' => $response->status(),
            'url' => $url,
            'message' => $message,
        ];
    }
}
