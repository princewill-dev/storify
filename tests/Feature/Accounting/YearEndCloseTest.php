<?php

use App\Models\FiscalYear;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerClosingService;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;

use function Pest\Laravel\actingAs;

test('closing a fiscal year transfers net income to retained earnings', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    $posting = app(LedgerPostingService::class);
    $rentAccountId = LedgerAccount::where('business_id', $business->id)->where('subtype', 'rent')->value('id');

    $posting->post($business->id, [
        ['account_key' => 'cash', 'debit' => 500000],
        ['account_key' => 'sales_income', 'credit' => 500000],
    ], ['idempotency_key' => 'test:close:income', 'date' => now()->startOfYear()->toDateString()]);

    $posting->post($business->id, [
        ['account_id' => $rentAccountId, 'debit' => 200000],
        ['account_key' => 'cash', 'credit' => 200000],
    ], ['idempotency_key' => 'test:close:expense', 'date' => now()->startOfYear()->toDateString()]);

    $entry = app(LedgerClosingService::class)->closeYear($business->id, (int) now()->year, $owner->id);

    expect($entry)->not->toBeNull()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebits())->toBe(500000)
        ->and($entry->totalCredits())->toBe(500000);

    $retained = $entry->lines()
        ->whereHas('account', fn ($q) => $q->where('subtype', 'retained_earnings'))
        ->first();

    expect($retained)->not->toBeNull()
        ->and($retained->credit_kobo)->toBe(300000);

    // Income and expense accounts were zeroed by the closing entry
    $incomeLines = $entry->lines()->whereHas('account', fn ($q) => $q->where('subtype', 'sales_income'))->first();
    expect($incomeLines->debit_kobo)->toBe(500000);

    // The year is locked
    $year = FiscalYear::where('business_id', $business->id)->where('name', (string) now()->year)->first();
    expect($year->status)->toBe('closed');

    // Closing twice is rejected
    expect(fn () => app(LedgerClosingService::class)->closeYear($business->id, (int) now()->year))
        ->toThrow(RuntimeException::class);
});

test('closing a year with no activity just locks it', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    $entry = app(LedgerClosingService::class)->closeYear($business->id, (int) now()->year, $owner->id);

    expect($entry)->toBeNull()
        ->and(FiscalYear::where('business_id', $business->id)->where('name', (string) now()->year)->first()->status)->toBe('closed');
});

test('a business owner can close the year from settings', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    actingAs($owner)
        ->post(route('management.accounting.settings.years.close', now()->year))
        ->assertSessionHasNoErrors();

    expect(FiscalYear::where('business_id', $business->id)->where('name', (string) now()->year)->first()->status)->toBe('closed');
});
