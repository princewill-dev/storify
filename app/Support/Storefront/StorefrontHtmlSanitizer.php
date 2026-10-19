<?php

namespace App\Support\Storefront;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The one sanitizer every owner-written storefront page goes through.
 *
 * HTML is cleaned **on write**, before it reaches the database. That is what
 * lets the public endpoint serve stored HTML without trusting it, and it means
 * an allow-list change later applies to new saves rather than silently
 * rewriting existing pages. The storefront sanitizes again on render with
 * DOMPurify — two independent implementations, because a stored-XSS hole is
 * not something one library's bug should be able to open.
 *
 * The allow-list is intentionally editorial: paragraphs, headings, lists,
 * quotes and links. No images (the storefront already has a product catalogue
 * and an owner-supplied image would need its own upload path), no tables, no
 * embeds, no inline styles. Links may point outward but are forced to carry
 * `rel="noopener noreferrer"` when they open a new tab.
 */
final class StorefrontHtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $this->purifier()->purify($html);
    }

    private function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $cache = storage_path('app/purifier');

        try {
            File::ensureDirectoryExists($cache);
        } catch (Throwable) {
            // A read-only filesystem makes Purifier slower, not unsafe. Leaving
            // this uncaught would turn every page save into a 500.
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
            'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'hr',
            'a[href|title|target|rel]',
        ]));
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'tel' => true,
        ]);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('Attr.AllowedFrameTargets', ['_blank', '_self']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('Cache.SerializerPath', $cache);

        return self::$purifier = new HTMLPurifier($config);
    }
}
