<?php

namespace App\Support\Payments;

/**
 * The URL a customer's browser is returned to, carrying the payment reference.
 *
 * ## Why the reference has to be put there by us
 *
 * The page that receives the customer has nothing but the URL to go on. It reads
 * a reference out of the query string and asks the API to verify that payment;
 * without one it cannot name what it is supposed to be confirming, and the
 * customer is left looking at a payment that succeeded at the provider and does
 * not exist as far as the screen is concerned.
 *
 * The callback page was written against Paystack, which appends
 * `?reference=…&trxref=…` to the `callback_url` it was given. Providers that
 * decorate the URL at all decorate it their own way, and Bachs' way is
 * `?checkout_id=chk_…` — its own name for the payment, which is not a value
 * `GET /subscription/callback` can look anything up by. Nothing in the codebase
 * had ever added a reference, so the working Paystack flow was an accident of
 * that provider's redirect convention rather than something this application
 * arranged, and every Bachs payment ended on the dead end below.
 *
 * So the reference is now part of the URL before the gateway is called. Every
 * provider then returns the customer to a page that can verify, whether it
 * decorates the URL itself or not.
 *
 * ## Why the key is ours
 *
 * `{@see self::QUERY_KEY}` and not `reference`. Paystack appends a `reference`
 * of its own, so reusing that name would put two values under one key — and a
 * duplicated query parameter reaches Vue Router as an *array*, where
 * `String(['ref_1', 'ref_1'])` is `"ref_1,ref_1"`: a reference that matches no
 * payment, breaking the one provider that worked. A name no provider writes
 * cannot collide, so the parameter is always present, always ours, and always
 * exactly one value.
 *
 * The readers try this key first and fall back to `reference`/`trxref`, so
 * checkouts opened before this existed still verify.
 *
 * The reference is **not a secret**: it names a payment, it does not authorise
 * anything. Every endpoint that acts on one scopes it to the business asking,
 * so a reference lifted from a URL buys a 404 and nothing else.
 */
final class CallbackUrl
{
    /** The query key the reference travels under. Never a provider's own name. */
    public const QUERY_KEY = 'storify_ref';

    /**
     * `$url`, with the reference set.
     *
     * A blank reference, or a string with no path to attach to, is returned
     * untouched rather than turned into something malformed — a caller with
     * nothing to say is not this method's problem to guess at.
     */
    public static function withReference(string $url, ?string $reference): string
    {
        $reference = trim((string) $reference);

        if ($url === '' || $reference === '') {
            return $url;
        }

        // The fragment comes off first, and goes back on last. `#` ends the
        // query string, so a key appended after one is part of the fragment: it
        // never reaches the server and `route.query` never sees it.
        [$base, $fragment] = self::splitOnce($url, '#');
        [$path, $query] = self::splitOnce($base, '?');

        // No path, so this is not a URL we can add to — a bare query, or a
        // scheme-less string. Left alone.
        if ($path === '') {
            return $url;
        }

        // null when the URL had no query at all, which `parse_str` does not
        // accept.
        parse_str($query ?? '', $params);

        // Set, not append. Two values under one key is the array case described
        // above; it also means a client-supplied `callback_url` cannot smuggle
        // in a reference of its own choosing and have the return page quote it.
        $params[self::QUERY_KEY] = $reference;

        $rebuilt = $path.'?'.http_build_query($params);

        return $fragment === null ? $rebuilt : $rebuilt.'#'.$fragment;
    }

    /**
     * Split on the first `$delimiter` only, so a value containing another one
     * keeps it.
     *
     * @return array{0: string, 1: string|null} the part before, and the rest or
     *                                          null when the delimiter is absent
     */
    private static function splitOnce(string $value, string $delimiter): array
    {
        $at = strpos($value, $delimiter);

        return $at === false
            ? [$value, null]
            : [substr($value, 0, $at), substr($value, $at + strlen($delimiter))];
    }
}
