<?php

namespace App\Support\Identity;

use Illuminate\Support\Str;

/**
 * The display name we can honestly derive from an email address.
 *
 * Signup asks for an email and a password and nothing else, and there are two
 * `name` columns that must still be filled: `users.name`, which is NOT NULL —
 * so omitting it is not an option — and `businesses.name`, which a plan cannot
 * be priced against nothing. Asking for either would put a form field in front
 * of somebody who has no reason yet to trust the product, so both are derived
 * here and both are corrected later, on the payment settings screen.
 *
 * Everything this returns is a placeholder, and it is documented as one. The
 * point of doing it carefully is that the placeholder is plausible enough to
 * read as a name in the window before it is corrected — it appears on the
 * /welcome page and, if a payment is made before the correction, on the
 * subscription receipt.
 *
 * The rule, in order:
 *
 * 1. the local part, before the `@`;
 * 2. its `+sub-address` stripped, so `john+shop@x` is John and not John Shop;
 * 3. anything that is not a letter or a digit becomes a separator, so `a..b@x`
 *    and `a_b@x` and `a!b@x` all read as two words;
 * 4. each word title-cased, through the multibyte-safe converter;
 * 5. **no letter anywhere means there is no name to be had** — `1234@x` is the
 *    documented fallback, because a business called "1234" is worse than a
 *    business called "My Business";
 * 6. truncated to the column width.
 *
 * Digits that sit *inside* a word are kept: `ada99@x` is Ada99, which is a
 * real shape of real name and nearer the truth than Ada. Unicode is handled
 * rather than survived — `ọgá@x` is Ọgá, not the fallback — which is why the
 * character classes below are `\p{L}` / `\p{N}` and not `[A-Za-z0-9]`.
 */
final class DerivedName
{
    /**
     * Used when the address carries no letters at all.
     */
    public const USER_FALLBACK = 'Store Owner';

    public const BUSINESS_FALLBACK = 'My Business';

    /**
     * The longest name either column will take (`users.name`,
     * `businesses.name` are both `varchar(255)`).
     */
    private const MAX_LENGTH = 255;

    public static function forUser(string $email): string
    {
        return self::fromEmail($email, self::USER_FALLBACK);
    }

    public static function forBusiness(string $email): string
    {
        return self::fromEmail($email, self::BUSINESS_FALLBACK);
    }

    private static function fromEmail(string $email, string $fallback): string
    {
        // `Str::before` returns the subject when there is no `@`, which is the
        // answer we want: a string with no domain is all local part.
        $local = Str::before(trim($email), '@');

        // The sub-address is routing information, not part of the name.
        $local = Str::before($local, '+');

        $words = preg_split('/[^\p{L}\p{N}]+/u', $local) ?: [];
        $words = array_values(array_filter($words, static fn (string $word) => $word !== ''));

        if ($words === []) {
            return $fallback;
        }

        $name = implode(' ', array_map(
            static fn (string $word) => mb_convert_case($word, MB_CASE_TITLE),
            $words,
        ));

        // Digits alone cannot make a name, and neither can punctuation that
        // survived as a word. A letter anywhere is enough to keep what we built.
        if (! preg_match('/\p{L}/u', $name)) {
            return $fallback;
        }

        // No ellipsis on the end: the limit is the column's, and "…" would
        // spend three of the 255 characters on saying so.
        return Str::limit($name, self::MAX_LENGTH, '');
    }
}
