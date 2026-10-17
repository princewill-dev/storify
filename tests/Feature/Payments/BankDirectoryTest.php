<?php

use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Payouts\BankDirectory;
use App\Services\Payments\Payouts\PayoutDestinationRef;
use App\Services\Payments\Payouts\PayoutRail;
use App\Services\Payments\Payouts\TransferOutcome;
use Illuminate\Support\Facades\Cache;

/*
| Translating a bank code from one payout provider's numbering into another's.
|
| `store_banks` holds a code from the picker's list — Paystack's — and a payout may
| leave through Bachs, which numbers the same institutions differently: Opay is
| `999992` there and `305` here. Handing the number over untranslated is what broke
| every fintech bank on the platform, so the translation has a real job to do.
|
| The test is mostly about *refusing* to translate. Bachs accepts a bank code for
| the wrong institution — a code it does not recognise for that bank comes back
| `200 approved is_usable: true`, with the account holder's name derived from the
| account number alone — so a wrong answer here is not caught downstream. It is
| money sent to another bank, and the only place it can be stopped is this one.
|
| That is why the rules run in the order they do, and why two of the cases below are
| banks that were *wrongly* matched until they were found in an audit: `Uzondu
| Microfinance Bank Awka Anambra State` was matched to `Imo State Microfinance
| Bank` on the word `state`, and `Kebbi Homes Savings and Loans` to `Imperial Homes
| Mortgage Bank` on `homes`. Both are a business's money going to the wrong bank.
|
| The rail is stubbed rather than faked at the HTTP layer, because what is being
| tested is the matching and not the two providers' JSON — and a stub is the only
| way to write the exact list a case needs.
*/

/**
 * A rail that lists whatever it is handed, and refuses to do anything else.
 *
 * The code is part of the stub rather than a constant, because it is the cache key:
 * two stubs sharing one would read each other's list, and the unreadable case would
 * quietly pass by finding the readable one's banks.
 *
 * @param  array<int, array{code: string, name: string, nibss?: string|null}>  $banks
 */
function bd58Rail(array $banks, string $code = 'stub', ?string $error = null): PayoutRail
{
    return new class($banks, $code, $error) implements PayoutRail
    {
        /** @param array<int, array{code: string, name: string, nibss?: string|null}> $banks */
        public function __construct(
            private readonly array $banks,
            private readonly string $code,
            private readonly ?string $error,
        ) {}

        public function code(): string
        {
            return $this->code;
        }

        public function banks(GatewayCredentials $credentials): array
        {
            if ($this->error !== null) {
                throw new RuntimeException($this->error);
            }

            return array_map(fn (array $bank): array => [
                'code' => (string) $bank['code'],
                'name' => (string) $bank['name'],
                'nibss' => $bank['nibss'] ?? null,
            ], $this->banks);
        }

        public function ensureDestination(
            GatewayCredentials $credentials,
            string $accountNumber,
            string $bankCode,
            ?string $accountName,
            string $currency = 'NGN',
        ): PayoutDestinationRef {
            throw new RuntimeException('not used');
        }

        public function send(
            GatewayCredentials $credentials,
            string $destinationId,
            int $amountKobo,
            string $reference,
            string $currency = 'NGN',
        ): TransferOutcome {
            throw new RuntimeException('not used');
        }

        public function availableBalance(GatewayCredentials $credentials): ?int
        {
            return null;
        }

        public function lookUp(
            GatewayCredentials $credentials,
            string $reference,
            ?string $providerPayoutId = null,
        ): ?TransferOutcome {
            throw new RuntimeException('not used');
        }

        public function canLookUpPayout(?string $providerPayoutId): bool
        {
            return true;
        }
    };
}

function bd58Credentials(): GatewayCredentials
{
    return GatewayCredentials::make('stub', ['secret_key' => 'test', 'public_key' => 'test']);
}

function bd58Directory(): BankDirectory
{
    return new BankDirectory;
}

// The list is cached per provider, so a list kept from the case before would be
// the list this one is asserting about. Cheaper to clear than to reason about.
beforeEach(fn () => Cache::flush());

/** The Bachs list, reduced to the rows the cases below turn on. */
function bd58Bachs(): PayoutRail
{
    return bd58Rail([
        ['code' => '305', 'name' => 'Opay (Paycom)'],
        ['code' => '035', 'name' => 'Wema Bank'],
        ['code' => '090277', 'name' => 'Al-Hayat Microfinance Bank'],
        ['code' => '838', 'name' => 'Imo State Microfinance Bank'],
        ['code' => '938', 'name' => 'Imperial Homes Mortgage Bank'],
        ['code' => '090165', 'name' => 'Petra Microfinance Bank'],
        ['code' => '058', 'name' => 'GTBank Plc'],
        ['code' => '057', 'name' => 'Zenith Bank'],
        ['code' => '100033', 'name' => 'PALMPAY', 'nibss' => '100033'],
        ['code' => '637', 'name' => 'Nova Merchant Bank', 'nibss' => '100023'],
    ]);
}

// A distinct code, so the unreadable stub cannot be answered from the readable
// one's cache entry.
function bd58Down(): PayoutRail
{
    return bd58Rail([], 'stub-down', 'connection reset');
}

test('a bank that renamed itself is matched on the word they still share', function () {
    // The case the translation exists for: the two lists agree on `opay` and on
    // nothing else, and a shared number there would be wrong.
    expect(bd58Directory()->codeFor(bd58Bachs(), bd58Credentials(), '999992', 'OPay Digital Services Limited (OPay)'))
        ->toBe('305');
});

test('a word that leads a name can carry the match on its own', function () {
    // Wema's own digital bank, listed under a name that shares only its second
    // word — which is still the name of the institution.
    expect(bd58Directory()->codeFor(bd58Bachs(), bd58Credentials(), '035A', 'ALAT by WEMA'))
        ->toBe('035');

    // And a typo in the provider's own list is not a reason to refuse a business.
    expect(bd58Directory()->codeFor(bd58Bachs(), bd58Credentials(), '50746', 'Petra Mircofinance Bank Plc'))
        ->toBe('090165');
});

test('a shared qualifier is not a match', function () {
    $directory = bd58Directory();

    // `state` is the only word these two have in common, and they are different
    // banks in different states. This matched until the loose rule was tightened,
    // and the money would have gone to Imo State.
    expect($directory->codeFor(bd58Bachs(), bd58Credentials(), '50894', 'Uzondu Microfinance Bank Awka Anambra State'))
        ->toBeNull();

    // Same shape, on `homes`. This one appeared only after the qualifier list grew,
    // which is the reason the rule is about a name's structure and not a list of
    // words.
    expect($directory->codeFor(bd58Bachs(), bd58Credentials(), '90028', 'Kebbi Homes Savings and Loans Limited'))
        ->toBeNull();
});

test('the code we already hold is used when the names share nothing', function () {
    // `Guaranty Trust Bank` and `GTBank Plc` have no word in common, and the code
    // is the same at both providers — which is the quiet case, and the one that
    // makes the legacy banks work.
    expect(bd58Directory()->codeFor(bd58Bachs(), bd58Credentials(), '058', 'Guaranty Trust Bank'))
        ->toBe('058');

    expect(bd58Directory()->codeFor(bd58Bachs(), bd58Credentials(), '057', 'Zenith Bank'))
        ->toBe('057');
});

test('a bank reachable only by its nibss number is found', function () {
    // Bachs keeps `100023` in `nibss_bank_code` while using `637` for the code, so
    // without this rule the entry is unreachable by the number we hold.
    expect(bd58Directory()->codeFor(bd58Bachs(), bd58Credentials(), '100023', 'Nova Bank'))
        ->toBe('637');
});

test('a bank this provider does not list is refused rather than guessed', function () {
    // Nothing here resembles it, and there is no code of ours that appears in the
    // list. Null is the answer: the caller turns it into a sentence for the
    // business instead of a payout that fails three times.
    expect(bd58Directory()->codeFor(bd58Bachs(), bd58Credentials(), '50894', 'Sparkle Microfinance Bank'))
        ->toBeNull();
});

test('an unreadable list is not an empty one', function () {
    $directory = bd58Directory();

    // The distinction the caller refuses a business on: a provider that lists no
    // bank of yours is a verdict, and a provider we could not reach is our outage.
    $down = bd58Down();

    expect($directory->readable($down, bd58Credentials()))->toBeFalse()
        ->and($directory->for($down, bd58Credentials()))->toBe([]);

    expect($directory->readable(bd58Bachs(), bd58Credentials()))->toBeTrue();
});

test('an empty answer is a list we did not get', function () {
    $empty = bd58Rail([], 'stub-empty');

    // Both providers carry hundreds of banks, so a response with none in it is the
    // endpoint having changed rather than a provider with nothing to pay. The
    // distinction is not academic: unreadable is recorded and retried, whereas a
    // real list with no match in it becomes a sentence telling a business its bank
    // cannot be paid. An empty response read as a real list is what turned GTBank
    // into an unpayable bank at setup, for every business at once.
    expect(bd58Directory()->readable($empty, bd58Credentials()))->toBeFalse()
        ->and(bd58Directory()->codeFor($empty, bd58Credentials(), '058', 'GTBank'))->toBeNull();
});
