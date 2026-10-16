<?php

namespace App\Services\Payments\Payouts;

use App\Services\Payments\Data\GatewayCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One provider's bank list, and the answer to "what does this bank call itself
 * over there".
 *
 * ## The problem this exists for
 *
 * A business picks its bank once, from one provider's list, and that list's code
 * is what gets stored. Payouts then leave through a *different* provider, which is
 * free to call the same institution something else and number it differently. It
 * does:
 *
 *     Opay          Paystack 999992   Bachs 305
 *     PalmPay       Paystack 999991   Bachs 100033
 *     Moniepoint    Paystack 50515    Bachs 090405
 *     Zenith        Paystack 057      Bachs 057
 *
 * A legacy commercial bank agrees by luck and the fintechs do not, which is the
 * worst possible split: everything works in a test with a GTB account while the
 * platform's most likely merchants — a shop banking with Opay or Moniepoint — are
 * unpayable.
 *
 * ## Why the match is not trusted on its own
 *
 * Bachs accepts a bank code for the wrong institution. Registering an Opay account
 * number against GTBank's `058` comes back `200`, `approved` and `is_usable: true`,
 * and the account name it derives is the *correct holder* — because the name
 * lookup resolves from the account number and ignores the code entirely. So a
 * wrong guess is not refused, and the name is no evidence that the guess was right.
 *
 * That is why every rule here has to be able to answer **"I do not know"**, and
 * why the caller refuses rather than falling back when it does. There is no join
 * key between the two lists — not code (they collide, and one collision is between
 * two genuinely different banks), not name (the spellings diverge), not slug (19
 * entries out of 290). So the match is done on names stripped to their
 * distinguishing words, accepted **only when exactly one candidate is left**, and
 * otherwise declined.
 *
 * ## The words that do not distinguish anything
 *
 * "Bank", "Limited", "Plc", "MFB", "Microfinance" and the rest appear in most rows
 * of both lists and in neither list's idea of a name. Dropping them is what makes
 * `OPay Digital Services Limited (OPay)` and `Opay (Paycom)` both reduce to
 * something that starts at `opay`, and it is also what stops a rule from matching
 * the wrong bank on a shared synonym.
 */
final class BankDirectory
{
    /**
     * Words that appear in most rows of both lists, and so identify nothing.
     *
     * Deliberately conservative: every word here is one that, if dropped and the
     * bank in question had *only* that word, would leave nothing to match on — at
     * which point the row is declined rather than matched loosely.
     *
     * Two kinds of word, and the second kind is the one that was learned the hard
     * way:
     *
     * - **corporate dressing** — "Bank", "Limited", "Plc", "Microfinance". Present in
     *   most rows and in neither list's idea of a name.
     * - **place and kind-of-institution words** — "State", "National", "Savings",
     *   "Trust". These look identifying and are not: `Uzondu Microfinance Bank Awka
     *   Anambra State` and `Imo State Microfinance Bank` are different institutions
     *   in different states, and `state` was the only word they shared. It is the
     *   sole word in the whole audit that produced a match to the wrong bank, and it
     *   got through because `state` happens to appear in exactly one Bachs row —
     *   rarity in one list is not the same property as identifying anything.
     *
     * `state` and friends are dropped rather than the loose pass being tightened
     * further because there is no rule that can tell a generic word from a
     * distinctive one by looking at a bank name; what makes `state` generic is the
     * language, and a list is how that is written down.
     */
    private const NOISE = [
        'bank', 'banks', 'limited', 'ltd', 'plc', 'nigeria', 'nigerian',
        'microfinance', 'micro', 'finance', 'mfb', 'mfi', 'mfbank',
        'financial', 'services', 'service', 'company', 'co',
        'the', 'and', 'of', 'group',

        // Geography and scope.
        'state', 'states', 'national', 'international', 'city', 'community',
        'rural', 'urban', 'provincial', 'regional', 'federal', 'local',

        // Kind of institution rather than its identity.
        'savings', 'loan', 'loans', 'credit', 'investment', 'investments',
        'commercial', 'merchant', 'merchants', 'industrial', 'development',
        'cooperative', 'holdings', 'ventures', 'enterprises',
        'capital', 'union', 'mortgage', 'mortgages', 'mortage',
        'trust', 'trustee', 'trustees',
    ];

    /**
     * How long a provider's list is trusted before it is fetched again.
     *
     * A bank rebranding or a code changing under us is a slow event, and the
     * lookup happens at account-setup and at backfill rather than on the payout
     * path, so the cost of a stale list is a setup step that needs re-running.
     */
    private const TTL_SECONDS = 86400;

    /** Normalised provider banks for this request, keyed by rail code. */
    private array $resolved = [];

    /**
     * The provider's banks as `code => name`.
     *
     * Empty on any failure. Empty is not "this provider has no banks" — it is "we
     * could not ask", and every caller here treats it as a refusal to match rather
     * than as a list with nothing in it. {@see self::readable()} is how a caller
     * tells the two apart, and the difference is not academic: "this provider does
     * not list your bank" is a permanent refusal to show a business, while "we
     * could not reach the provider" must never stop one signing up.
     *
     * @return array<string, string>
     */
    public function for(PayoutRail $rail, GatewayCredentials $credentials): array
    {
        $out = [];

        foreach ($this->records($rail, $credentials) ?? [] as $record) {
            $out[$record['code']] = $record['name'];
        }

        return $out;
    }

    /**
     * Whether this provider's list was actually read.
     *
     * False means the answer to every question below is null for want of
     * information rather than because the answer is no — which is the whole
     * distinction a caller deciding whether to refuse a business needs.
     *
     * **An empty answer counts as not read.** Both providers carry hundreds of
     * banks, so a response containing none is the endpoint having changed or an
     * answer that did not parse; there is no such thing here as a provider that
     * lists nothing. The two readings are not interchangeable at the callers
     * either: a false here is recorded as unanswered and tried again, whereas a
     * null from {@see self::codeFor()} reached through `true` becomes a sentence
     * telling a business its bank cannot be paid. One provider's bad response must
     * not be reported to every business as its bank being unpayable — the same
     * over-reach {@see PayoutBankResolution::unchecked()} exists to prevent.
     */
    public function readable(PayoutRail $rail, GatewayCredentials $credentials): bool
    {
        return ($this->records($rail, $credentials) ?? []) !== [];
    }

    /**
     * The code this provider uses for a bank we hold under another provider's code
     * and name, or null when the answer is not certain.
     *
     * Two rules, and the order is the safety property:
     *
     * 1. **A unique match on the distinguishing words.** Exactly one candidate, or
     *    nothing. This runs *first* — not because names are more trustworthy than
     *    numbers, but because a shared number is the one thing here that can be
     *    actively wrong. Three Paystack codes name a different institution at
     *    Bachs: `50739` is Goodnews to Paystack and Prospa Capital to Bachs, and
     *    `100025` and `311` are two more. A payout by name lands on the right
     *    institution in all three; a payout by number would not have.
     *
     * 2. **The code we already hold, if this provider lists it.** This is what
     *    carries the banks whose names diverge past matching — `Guaranty Trust
     *    Bank` is `GTBank Plc`, `First City Monument Bank` is `FCMB Plc`, and
     *    neither shares a distinguishing word with the other. It is also the only
     *    rule that can serve a bank this provider has rebranded.
     *
     *    The NIBSS number is tried as well as the primary code, and not as a
     *    convenience: `100025` and `100023` are NIBSS numbers that Bachs keeps in
     *    its `nibss_bank_code` field while using something else for `code`, so
     *    without this those two entries would be unreachable.
     *
     * A tie in rule 1 is declined rather than broken arbitrarily, and a miss in
     * both is declined rather than guessed. The two ways of being wrong are not
     * comparable: refusing to set up a payout account is a support ticket, and
     * paying a business's money into another institution is not.
     *
     * ## What this does not cover, and why that is the honest answer
     *
     * Bachs lists 286 banks and Paystack 290, and they overlap by about 133. Every
     * commercial bank and every large fintech is in that overlap; a long tail of
     * microfinance banks exists at Paystack and nowhere else. A business banking
     * with one of those cannot be paid through this rail at all, and the null here
     * is how that is said — the setup step turns it into a refusal naming the bank,
     * rather than a payout that fails three times and blames the provider.
     *
     * @param  string  $bankCode  the code we hold, in another provider's space
     * @param  string  $bankName  the name we hold
     * @return string|null the code in $rail's space, or null when not certain
     */
    public function codeFor(
        PayoutRail $rail,
        GatewayCredentials $credentials,
        string $bankCode,
        string $bankName,
    ): ?string {
        $records = $this->records($rail, $credentials);

        if ($records === null || $records === []) {
            return null;
        }

        $matches = $this->uniqueNameMatch($records, $bankName);

        if ($matches !== null) {
            if ($matches !== $bankCode) {
                Log::info('payout.bank_directory.matched_by_name', [
                    'provider' => $rail->code(),
                    'held_code' => $bankCode,
                    'held_name' => $bankName,
                    'matched_code' => $matches,
                ]);
            }

            return $matches;
        }

        foreach ($records as $record) {
            if ($record['code'] === $bankCode) {
                return $record['code'];
            }
        }

        foreach ($records as $record) {
            if ($record['nibss'] !== null && $record['nibss'] === $bankCode) {
                Log::info('payout.bank_directory.matched_by_nibss', [
                    'provider' => $rail->code(),
                    'held_code' => $bankCode,
                    'held_name' => $bankName,
                    'matched_code' => $record['code'],
                    'matched_name' => $record['name'],
                ]);

                return $record['code'];
            }
        }

        return null;
    }

    /**
     * The one candidate sharing a distinguishing word with ours, or null when there
     * is none or more than one.
     *
     * ## Why one shared word and not all of them
     *
     * The obvious rule — every one of our words appears in theirs — is wrong in the
     * direction that costs the most. `OPay Digital Services Limited (OPay)` reduces
     * to `{opay, digital}` and Bachs calls the same institution `Opay (Paycom)`,
     * `{opay, paycom}`. Requiring both words finds nothing, so Opay — one of the
     * two or three wallets a Nigerian shop is most likely to bank with — comes back
     * as a bank the provider cannot pay, and the business is told to change its
     * bank account.
     *
     * The distinct words are what identify a bank, and a name is mostly made of
     * them: `opay` alone is the whole of the identity, and `digital`, `paycom` and
     * `limited` are dressing either provider may or may not include. So one shared
     * distinguishing word is taken as the signal, and the **uniqueness requirement
     * is what carries the safety** — a word that matches two institutions is
     * discarded rather than broken, and the caller falls back to the bank code.
     *
     * That fallback is why relaxing this is safe rather than merely convenient.
     * Every bank whose name is too generic to match uniquely still resolves by code:
     * `Access Bank` returns two candidates at Bachs, so it declines here and is
     * answered by `044`.
     *
     * @param  array<int, array{code: string, name: string, nibss: string|null}>  $records
     */
    private function uniqueNameMatch(array $records, string $bankName): ?string
    {
        $wanted = $this->distinguishingWords($bankName);

        if ($wanted === []) {
            // Nothing left to match on — a bank whose name really is just "Bank".
            // Declined rather than matched on an empty set, which would match
            // every row in the list equally.
            return null;
        }

        // Strict first, and it is not redundant with the loose pass below. A bank
        // whose every distinguishing word appears in exactly one candidate is
        // matched with the strongest evidence available; asking loosely first would
        // let an unrelated candidate sharing one common word create a tie and push
        // the bank down to its code — which measured as a loss of sixteen banks
        // when this was a single loose pass.
        return $this->match($records, $wanted, requireAll: true)
            ?? $this->match($records, $wanted, requireAll: false);
    }

    /**
     * The one candidate matching on these words, or null when there is none or more
     * than one.
     *
     * `requireAll` is the difference between "every word identifies it" and "one
     * word identifies it", and the caller asks for both in that order. A tie is
     * discarded either way rather than broken arbitrarily — the caller falls back to
     * the bank code, and a code that is itself wrong is caught by the strict pass
     * having already run.
     *
     * @param  array<int, array{code: string, name: string, nibss: string|null}>  $records
     * @param  array<int, string>  $wanted
     */
    private function match(array $records, array $wanted, bool $requireAll): ?string
    {
        $matches = [];

        foreach ($records as $record) {
            $candidate = $this->distinguishingWords($record['name']);

            if ($candidate === []) {
                continue;
            }

            $shared = array_intersect($wanted, $candidate);

            if ($shared === []) {
                continue;
            }

            if ($requireAll && array_diff($wanted, $candidate) !== []) {
                continue;
            }

            // Two more gates on the loose pass, both of them the product of a wrong
            // match found in an audit of one-word matches rather than of caution in
            // the abstract. Bachs accepts a wrong code silently, so every one of
            // these is money sent to the wrong institution.
            //
            // `Uzondu Microfinance Bank Awka Anambra State` and `Imo State
            // Microfinance Bank` are different banks in different states, and
            // `Kebbi Homes Savings and Loans` and `Imperial Homes Mortgage Bank`
            // have nothing to do with each other. Both matched, on `state` and on
            // `homes`, and both would have paid a business's money to the wrong
            // place.
            if (! $requireAll) {
                // The word has to be one that names something in this list rather
                // than a word that happens to be rare in it — `state` appears in
                // exactly one Bachs row and still identifies nothing. A property of
                // the list, so it needs no maintenance as banks are added.
                if (! $this->isDistinctive($records, $shared)) {
                    continue;
                }

                // And it has to be a word that *leads* a name, on one side or the
                // other. A Nigerian bank's identity is the first thing it says:
                // `Opay`, `Petra`, `Wema`, `Uzondu`, `Kebbi` — and what follows is
                // the kind of institution it is, which is exactly the part that is
                // generic. `Homes`, `State`, `Savings` and `Loans` all sit in that
                // trailing position, which is why the two false matches above share
                // the shape "two names that agree on a qualifier and disagree on the
                // name" and why this rejects them without knowing the word in
                // advance.
                //
                // This is a rule about a name rather than a list of words, so it
                // catches the qualifier nobody has thought of yet. It costs the
                // matches whose only shared word is a trailing one — `Jubilee Life`
                // against `Jubliee Life`, where a typo leaves `life` carrying it —
                // and pays for itself by never having to weigh a wrong bank against
                // a missed one.
                if (array_intersect($shared, [$wanted[0], $candidate[0]]) === []) {
                    continue;
                }
            }

            $matches[$record['code']] = true;
        }

        return count($matches) === 1 ? (string) array_key_first($matches) : null;
    }

    /**
     * Whether any of these words appears in exactly one row of the list.
     *
     * One row, not "few": a threshold would have to be argued for and would drift as
     * the list grows, whereas "this word names one institution in this list" is the
     * property actually being relied on.
     *
     * Counted in full rather than short-circuited on the first word to reach two,
     * because a row can carry several of the shared words at once and stopping early
     * would report a word as unique before the row that repeats it had been seen.
     *
     * @param  array<int, array{code: string, name: string, nibss: string|null}>  $records
     * @param  array<int, string>  $shared
     */
    private function isDistinctive(array $records, array $shared): bool
    {
        $counts = array_fill_keys($shared, 0);

        foreach ($records as $record) {
            $candidate = array_flip($this->distinguishingWords($record['name']));

            foreach ($shared as $word) {
                if (isset($candidate[$word])) {
                    $counts[$word]++;
                }
            }
        }

        return in_array(1, $counts, true);
    }

    /**
     * The words in a bank's name that identify it, lowercased and deduplicated.
     *
     * @return array<int, string>
     */
    private function distinguishingWords(string $name): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($name)) ?: [];

        $kept = array_filter($words, fn (string $word): bool => $word !== ''
            && ! in_array($word, self::NOISE, true));

        return array_values(array_unique($kept));
    }

    /**
     * This provider's banks, normalised and de-duplicated by code.
     *
     * **Null means the list could not be read**, and that is the whole reason this
     * returns a nullable rather than an empty array: the callers above have to
     * distinguish "this provider does not list your bank" from "we could not ask",
     * and an empty array cannot say which.
     *
     * Cached per provider, not per bank: the whole list is read at once anyway and
     * every rule here wants all of it.
     *
     * @return array<int, array{code: string, name: string, nibss: string|null}>|null
     */
    private function records(PayoutRail $rail, GatewayCredentials $credentials): ?array
    {
        $code = $rail->code();

        if (array_key_exists($code, $this->resolved)) {
            return $this->resolved[$code];
        }

        $cached = Cache::get($this->key($code));

        if (! is_array($cached)) {
            $cached = $this->fetch($rail, $credentials);

            if ($cached === null) {
                return null;
            }

            if ($cached !== []) {
                Cache::put($this->key($code), $cached, self::TTL_SECONDS);
            }
        }

        return $this->resolved[$code] = $cached;
    }

    /**
     * Ask the provider, and make its answer uniform.
     *
     * Null on any failure, and deliberately not cached then: a provider that was
     * briefly unreachable should be asked again next time rather than remembered as
     * having no banks for a day.
     *
     * A successful answer that happens to be empty is reported as it arrived — `[]`
     * — and not as null, because that is what came back. It is logged, and then
     * {@see self::readable()} treats it as a list we did not get: on both of these
     * providers an empty answer means the endpoint changed, so it is our failure to
     * read rather than a provider with nothing to pay.
     *
     * @return array<int, array{code: string, name: string, nibss: string|null}>|null
     */
    private function fetch(PayoutRail $rail, GatewayCredentials $credentials): ?array
    {
        try {
            $banks = $rail->banks($credentials);
        } catch (\Throwable $e) {
            Log::warning('payout.bank_directory.unreadable', [
                'provider' => $rail->code(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($banks === []) {
            Log::warning('payout.bank_directory.empty', ['provider' => $rail->code()]);
        }

        $records = [];

        foreach ($banks as $bank) {
            // Codes are compared as strings everywhere above, because they are
            // strings — PHP turns a numeric-string array key into an int, and a
            // code that arrives as `305` one day and `'305'` the next would stop
            // matching itself.
            $code = isset($bank['code']) ? (string) $bank['code'] : '';
            $name = isset($bank['name']) ? trim((string) $bank['name']) : '';
            $nibss = isset($bank['nibss']) && $bank['nibss'] !== '' ? (string) $bank['nibss'] : null;

            if ($code === '' || $name === '') {
                continue;
            }

            $records[$code] = ['code' => $code, 'name' => $name, 'nibss' => $nibss];
        }

        return array_values($records);
    }

    private function key(string $provider): string
    {
        return 'payout.bank_directory.'.$provider;
    }
}
