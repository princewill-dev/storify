<?php

namespace App\Services\Payments\Payouts;

/**
 * A provider's answer, made safe to leave in a log file.
 *
 * ## Why this exists
 *
 * A payout acknowledgement is the one place a provider echoes back everything it
 * knows about where the money is going: the destination's account number, the
 * account holder's name, sometimes the customer the charge came from. The moment
 * somebody asks "did the provider actually receive this request, and what did it
 * say" — which is the first question asked when a transfer does not appear in the
 * provider's own dashboard — that answer needs to be in the log, and the raw body
 * cannot be.
 *
 * Log files are the wrong place for that. They are shipped off the host, read by
 * whoever is on support, retained far longer than the row is, and are exactly the
 * artefact a breach is reconstructed from. A payout reference is fine; a bank
 * account number is not. So the acknowledgement is logged redacted, and the
 * unredacted body stays where it already was — on the payout row's `meta`, which
 * is in our own database, behind our own access rules, next to the account number
 * the row already carries.
 *
 * ## Redacted by name, not by position
 *
 * The key list below matches on a **normalised** key — lowercased with every
 * non-alphanumeric character dropped — and matches anywhere inside it, so
 * `account_number`, `accountNumber` and `Account Number` are one rule rather than
 * three, and `destination.account_name` is caught without knowing it is nested.
 *
 * The bias is deliberately toward over-redaction. A redacted field that was
 * harmless costs somebody one debugging step; a leaked account number does not
 * come back. The fields a payout investigation actually needs — `id`, `reference`,
 * `status`, `amount`, `currency`, codes and timestamps — match none of these rules
 * and survive intact, which is what makes the redacted body still worth logging.
 *
 * ## Bounded as well as redacted
 *
 * Depth, breadth and string length are all capped. A provider that answers a
 * payout request with a megabyte of unrelated payload should not fill a log, and
 * an unbounded walk over an untrusted structure is its own problem.
 */
final class ProviderPayload
{
    /** What a redacted value is replaced with, so its absence is legible as a decision. */
    public const REDACTED = '[redacted]';

    /** What a value cut short by {@see self::MAX_STRING} ends with. */
    public const TRUNCATED = '…[truncated]';

    private const MAX_DEPTH = 5;

    private const MAX_ITEMS = 25;

    private const MAX_KEYS = 40;

    private const MAX_STRING = 200;

    /**
     * Key fragments that mean "do not log this", matched against the normalised key.
     *
     * Grouped by what they protect rather than alphabetically, because the list is
     * only maintainable if the next person can see which category a new field
     * belongs to.
     */
    private const SENSITIVE = [
        // Where the money is going — the whole reason a payout payload is risky.
        'account', 'bankaccount', 'iban', 'swift', 'routing', 'sortcode',

        // Who it is going to, and who it came from.
        'name', 'email', 'phone', 'msisdn', 'address', 'street', 'postcode',
        'zipcode', 'customer', 'recipient', 'beneficiary', 'holder', 'contact',

        // Identity numbers. Nigeria's BVN and NIN are the worst of these: unlike a
        // card number they cannot be rotated.
        'bvn', 'nin', 'ssn', 'dob', 'birth', 'taxid',

        // Credentials, which have no business in a response body and appear in them
        // more often than anybody would like.
        'token', 'secret', 'password', 'passcode', 'apikey', 'privatekey',
        'authorization', 'signature', 'card', 'cvv', 'credential',

        // Free-text fields a provider fills with whatever it was told, which in a
        // refusal is frequently the account holder's name.
        'metadata', 'narration', 'description',
    ];

    /**
     * Redact one provider response body for logging.
     *
     * Never throws and never returns the input unchanged by accident: anything it
     * cannot walk is replaced with a string saying so, because a value it failed to
     * inspect is exactly the value that might have leaked.
     */
    public static function redact(mixed $payload): mixed
    {
        return self::walk($payload, 0);
    }

    /**
     * Scrub the identifying fragments out of a provider's prose.
     *
     * The acknowledgement's *body* is redacted by key, but its `message` is a
     * sentence a provider wrote, and Bachs' refusal text is the kind that reads
     * "the account name does not match for 0123456789". There is no key to match
     * on there, so this removes the shapes instead: Nigerian account numbers,
     * email addresses, and anything that looks like a phone number.
     *
     * Deliberately narrow. It runs over an operator-facing sentence, and mangling
     * the sentence is a real cost — an amount or a reference in there is the most
     * useful part of the message. So a ten-digit run qualified by a decimal point
     * (`1234567890.00`, an amount) is left alone, and so is anything shorter or
     * longer, which keeps references and timestamps intact.
     */
    public static function scrubText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // A ten-digit run not followed by a decimal, and not part of a longer
        // number — which is what a NUBAN account number looks like and what an
        // amount or a timestamp does not.
        $text = (string) preg_replace('/(?<!\d)\d{10}(?!\d|\.\d)/', '[redacted]', $text);

        $text = (string) preg_replace(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            self::REDACTED,
            $text,
        );

        // Nigerian mobiles, with or without the country code, and the spaced and
        // dashed ways people write them.
        //
        // Eleven digits national (`0803 123 4567`) or thirteen international
        // (`+234 803 123 4567`), which is why it is `\d{2}` after the leading
        // digit and not `\d{1}`, and why the group is anchored on `[789]`: the
        // shorter run a `\d{1}` would match is not a phone number at all, and the
        // first draft of this pattern silently left `08031234567` in the log while
        // reporting that it had scrubbed the sentence.
        $text = (string) preg_replace(
            '/(?:\+?234|0)[\s\-]?[789]\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/',
            self::REDACTED,
            $text,
        );

        return self::cap($text);
    }

    private static function walk(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[nested deeper than '.self::MAX_DEPTH.' levels]';
            }

            $out = [];
            $seen = 0;

            foreach ($value as $key => $item) {
                if ($seen >= self::MAX_KEYS) {
                    $out['…'] = '['.count($value).' keys in total]';

                    break;
                }

                $seen++;

                // A numeric key means a list, and a list has no field name to match
                // on — so the item is walked and its own keys are what get checked.
                if (is_int($key)) {
                    if ($depth >= self::MAX_DEPTH - 1 && $seen > self::MAX_ITEMS) {
                        $out['…'] = '['.count($value).' items in total]';

                        break;
                    }

                    $out[$key] = self::walk($item, $depth + 1);

                    continue;
                }

                $out[$key] = self::isSensitive((string) $key)
                    ? self::REDACTED
                    : self::walk($item, $depth + 1);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::cap($value);
        }

        // Ints, floats, bools and null carry no identifiers, and an object would
        // have to be serialised to be judged — so it is not returned as itself.
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[unreadable '.get_debug_type($value).']';
    }

    private static function isSensitive(string $key): bool
    {
        $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        if ($normalised === '') {
            return false;
        }

        foreach (self::SENSITIVE as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function cap(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_STRING) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING).self::TRUNCATED;
    }
}
