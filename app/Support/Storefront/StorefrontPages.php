<?php

namespace App\Support\Storefront;

use App\Models\StorefrontPage;
use Illuminate\Support\Str;

/**
 * The small pieces of page identity that the API and the storefront SPA have
 * to agree on.
 *
 * `path()` is the one that matters: it is returned to the management app and
 * the public payloads, and storify-storefront's router registers the matching
 * routes. A custom page lives under `/pages/…` so it can never collide with a
 * root route such as `/cart`; the three pages every shop is expected to have
 * get the friendlier root URL.
 */
final class StorefrontPages
{
    /** Slugs that get `/{slug}` rather than `/pages/{slug}`. */
    public const FRIENDLY_SLUGS = ['about', 'terms', 'privacy'];

    /**
     * Pages offered as one-click starters in the management editor. Not
     * seeded server-side: an owner who does not want a Terms page should not
     * have an empty one to delete.
     *
     * @var array<int, array{slug: string, title: string}>
     */
    public const STARTERS = [
        ['slug' => 'about', 'title' => 'About us'],
        ['slug' => 'terms', 'title' => 'Terms & conditions'],
        ['slug' => 'privacy', 'title' => 'Privacy policy'],
    ];

    /** Bounds the `/seo` payload the Cloudflare Worker fetches per request. */
    public const MAX_PER_STORE = 50;

    public static function path(string $slug): string
    {
        return in_array($slug, self::FRIENDLY_SLUGS, true)
            ? '/'.$slug
            : '/pages/'.$slug;
    }

    /**
     * A page's content as a meta description.
     *
     * Tags are stripped rather than the HTML being escaped: the description is
     * shown in a social card as plain text, and an unclosed tag in the source
     * must not leak into it.
     */
    public static function excerpt(?string $html, int $limit = 160): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags((string) $html)) ?? '';

        return trim(Str::limit(trim($text), $limit, ''));
    }

    /**
     * @return array<int, array{slug: string, title: string}>
     */
    public static function startersFor(iterable $pages): array
    {
        $taken = [];

        foreach ($pages as $page) {
            $taken[] = $page instanceof StorefrontPage ? $page->slug : (string) $page;
        }

        return array_values(array_filter(
            self::STARTERS,
            fn (array $starter) => ! in_array($starter['slug'], $taken, true),
        ));
    }
}
