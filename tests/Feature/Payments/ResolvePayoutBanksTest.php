<?php

use App\Models\PayoutBankCode;
use App\Models\StoreBank;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Payouts\BachsPayoutRail;
use App\Services\Payments\Payouts\PayoutDestinationRef;
use App\Services\Payments\Payouts\PayoutRail;
use App\Services\Payments\Payouts\TransferOutcome;
use Illuminate\Support\Facades\Cache;

/*
| `custody:resolve-payout-banks` — the repair for the accounts already on file.
|
| The command exists because the accounts it repairs are the ones already holding
| money: a business named its bank out of the picker, which is Paystack's list, and
| its payout left through Bachs, which numbers the same institutions differently.
| The number went over untranslated, Bachs refused it with `BANK_NOT_PAYABLE`, and
| the platform filed it as a provider fault.
|
| What is pinned here is the behaviour that makes it safe to point at production:
|
| - **A dry run is the default and writes nothing at the provider.** These are
|   outbound calls, and one of them creates an object that is not ours to delete.
| - **An account already resolved is never re-resolved.** Bachs does not
|   deduplicate — three identical registrations returned three different ids — so a
|   command that re-registered on every run would grow destinations without bound.
|   This is the assertion that keeps `--apply` repeatable.
| - **"Cannot pay this bank" and "we could not ask" stay apart.** Both leave an
|   account unusable and only the first is a sentence to show a business; the second
|   is our own failed read, recorded and left to be retried.
|
| The rail is stubbed rather than faked at the HTTP layer: the registry resolves
| rails out of the container, so binding the concrete class is enough to test the
| command's decisions without pinning either provider's JSON.
*/

/**
 * A rail that lists what it is handed and records what it is asked to register.
 *
 * @param  array<int, array{code: string, name: string, nibss?: string|null}>  $banks
 */
function rpbRail(
    array $banks,
    string $code = 'stub-bachs',
    ?string $refusal = null,
    ?string $error = null,
): PayoutRail {
    return new class($banks, $code, $refusal, $error) implements PayoutRail
    {
        public int $registrations = 0;

        /** @param array<int, array{code: string, name: string, nibss?: string|null}> $banks */
        public function __construct(
            private readonly array $banks,
            private readonly string $code,
            private readonly ?string $refusal,
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
            $this->registrations++;

            if ($this->refusal !== null) {
                return PayoutDestinationRef::refused($this->refusal);
            }

            return PayoutDestinationRef::registered('pd_test', true);
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

/** Bachs, in the shape the command reads: the picker's name and Bachs' own code. */
function rpbBachs(?string $refusal = null, ?string $error = null): PayoutRail
{
    return rpbRail([
        ['code' => '305', 'name' => 'Opay (Paycom)'],
        ['code' => '058', 'name' => 'GTBank Plc'],
    ], 'stub-bachs', $refusal, $error);
}

function rpbUse(PayoutRail $rail): void
{
    app()->bind(BachsPayoutRail::class, fn () => $rail);

    config([
        'services.bachs.api_key' => 'sk_sandbox_platform1234',
        'services.bachs.webhook_secret' => 'whsec_platform',
    ]);
}

/** An account as the picker saved it: Paystack's code, under Paystack's name. */
function rpbBank(string $code = '999992', string $name = 'OPay Digital Services Limited (OPay)'): StoreBank
{
    [, $business] = createBusinessOwner();

    return StoreBank::factory()->create([
        'business_id' => $business->id,
        'bank_code' => $code,
        'bank_name' => $name,
    ]);
}

// The provider's list is cached per rail code, so one case's list would be the
// next one's answer.
beforeEach(fn () => Cache::flush());

test('the dry run reports the translation and writes nothing', function () {
    $rail = rpbBachs();
    rpbUse($rail);

    $bank = rpbBank();

    // One expectation per emitted write: `expectsOutputToContain` matches against
    // the individual writes a command makes, and a write already claimed by one
    // expectation cannot satisfy a second. So the row, the summary table and the
    // closing line are asked for separately rather than as halves of one line.
    //
    // Nothing here names the row's id: the database is rolled back between tests but
    // its auto-increment is not, so `bank#1` is only ever true in a run that happens
    // to start this table empty. The end of the row line carries the same claim —
    // which code it arrived at, and that it was a translation — without the id.
    $this->artisan('custody:resolve-payout-banks', ['--provider' => 'bachs'])
        ->expectsOutputToContain('-> 305 (translated from 999992)')
        ->expectsOutputToContain('would-resolve')
        ->expectsOutputToContain('1 account(s) needed their bank code translated')
        ->expectsOutputToContain('Nothing was written.')
        ->assertSuccessful();

    // The point of the default: nothing at the provider, nothing on our books.
    expect($rail->registrations)->toBe(0)
        ->and(PayoutBankCode::query()->count())->toBe(0);
});

test('--apply stores the translated code and the destination', function () {
    $rail = rpbBachs();
    rpbUse($rail);

    $bank = rpbBank();

    $this->artisan('custody:resolve-payout-banks', ['--provider' => 'bachs', '--apply' => true])
        ->expectsOutputToContain('Wrote 1 payout_bank_codes row(s).')
        ->assertSuccessful();

    $row = PayoutBankCode::query()->where('store_bank_id', $bank->id)->sole();

    // `305`, not `999992`: the number Bachs knows Opay by. This is the whole bug.
    expect($row->bank_code)->toBe('305')
        ->and($row->destination_id)->toBe('pd_test')
        ->and($row->is_usable)->toBeTrue()
        ->and($row->payable())->toBeTrue()
        ->and($rail->registrations)->toBe(1);
});

test('a second --apply leaves a resolved account alone', function () {
    $rail = rpbBachs();
    rpbUse($rail);

    rpbBank();

    $this->artisan('custody:resolve-payout-banks', ['--provider' => 'bachs', '--apply' => true])
        ->assertSuccessful();

    // Bachs does not deduplicate: asking it to register the same account again
    // returns a *second* destination id. So the second run must not ask.
    //
    // `unchanged` is only ever reported in the summary table, not as a per-row
    // line, so this is the count that carries it — and the write count is asserted
    // too, because a run that writes nothing must not say it wrote anything.
    $this->artisan('custody:resolve-payout-banks', ['--provider' => 'bachs', '--apply' => true])
        ->expectsOutputToContain('unchanged')
        ->expectsOutputToContain('Nothing to write')
        ->assertSuccessful();

    expect($rail->registrations)->toBe(1)
        ->and(PayoutBankCode::query()->count())->toBe(1);
});

test('an account the provider will not accept is recorded with its reason', function () {
    $rail = rpbBachs(refusal: 'Bachs refused this bank account for payouts.');
    rpbUse($rail);

    $bank = rpbBank(code: '058', name: 'GTBank');

    $this->artisan('custody:resolve-payout-banks', ['--provider' => 'bachs', '--apply' => true])
        ->expectsOutputToContain('[refused]')
        ->assertSuccessful();

    $row = PayoutBankCode::query()->where('store_bank_id', $bank->id)->sole();

    // The code is kept even though the account was refused: the next attempt
    // should not have to work the name out again.
    expect($row->bank_code)->toBe('058')
        ->and($row->destination_id)->toBeNull()
        ->and($row->refused())->toBeTrue()
        ->and($row->failure_reason)->toBe('Bachs refused this bank account for payouts.');
});

test('a provider we cannot read is unchecked rather than refused', function () {
    // An unreadable list means every bank looks unlisted, so a command that called
    // that a refusal would tell every business on the platform that its bank cannot
    // be paid — and write the sentence down as though a provider had said it.
    $rail = rpbBachs(error: 'connection reset');
    rpbUse($rail);

    $bank = rpbBank();

    $this->artisan('custody:resolve-payout-banks', ['--provider' => 'bachs', '--apply' => true])
        ->expectsOutputToContain('[unchecked]')
        ->assertSuccessful();

    $row = PayoutBankCode::query()->where('store_bank_id', $bank->id)->sole();

    expect($row->refused())->toBeFalse()
        ->and($row->is_usable)->toBeNull()
        ->and($row->destination_id)->toBeNull()
        ->and($rail->registrations)->toBe(0);
});

test('an empty answer is treated as unreadable too', function () {
    // The same rule one step further in: a provider that answers with a list of
    // nothing has not said your bank is unpaidable, it has said nothing at all.
    $rail = rpbRail([], 'stub-bachs');
    rpbUse($rail);

    $bank = rpbBank();

    $this->artisan('custody:resolve-payout-banks', ['--provider' => 'bachs', '--apply' => true])
        ->expectsOutputToContain('[unchecked]')
        ->assertSuccessful();

    expect(PayoutBankCode::query()->where('store_bank_id', $bank->id)->sole()->refused())->toBeFalse();
});

test('--business limits the run to one business', function () {
    rpbUse(rpbBachs());

    [$owner, $business] = createBusinessOwner();

    $mine = StoreBank::factory()->create([
        'business_id' => $business->id,
        'bank_code' => '999992',
        'bank_name' => 'OPay Digital Services Limited (OPay)',
    ]);

    $theirs = rpbBank();

    $this->artisan('custody:resolve-payout-banks', [
        '--provider' => 'bachs',
        '--apply' => true,
        '--business' => $business->id,
    ])->assertSuccessful();

    expect(PayoutBankCode::query()->where('store_bank_id', $mine->id)->exists())->toBeTrue()
        ->and(PayoutBankCode::query()->where('store_bank_id', $theirs->id)->exists())->toBeFalse();
});
