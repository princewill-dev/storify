<?php

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;

/*
|--------------------------------------------------------------------------
| Accounting — Year-end close, ported from the legacy web tests
|--------------------------------------------------------------------------
|
| The legacy tests/Feature/Accounting/YearEndCloseTest.php exercised the
| Blade settings screen. Its with-activity close (net income to retained
| earnings, income zeroed, year locked, second close refused) and the owner
| permission case are already covered by ws37accountingsettingsTest.php and
| ad13platformaccountingTest.php. The gap left behind is the no-activity
| close: it must lock the year without posting any closing entry.
|
*/

function yearEndCloseToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

test('closing a year with no activity locks the year without posting a closing entry', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    $year = (int) now()->year;

    $response = $this->withToken(yearEndCloseToken($owner))
        ->postJson("/api/v1/management/accounting/settings/years/{$year}/close");

    $response->assertOk()
        // Nothing was closed out, so there is no closing entry to return.
        ->assertJsonPath('data.entry', null)
        ->assertJsonPath('data.fiscal_year.status', 'closed')
        ->assertJsonPath('message', "Fiscal year {$year} closed.");

    // No income or expense activity means no closing journal entry is written.
    expect(JournalEntry::query()
        ->where('business_id', $business->id)
        ->where('idempotency_key', "year_close:{$year}")
        ->exists())->toBeFalse();

    $fiscalYear = FiscalYear::query()
        ->where('business_id', $business->id)
        ->where('name', (string) $year)
        ->firstOrFail();

    // The year is locked even though the ledger had nothing to transfer —
    // every period inside it ends up closed.
    expect($fiscalYear->status)->toBe('closed')
        ->and(FiscalPeriod::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', '!=', 'closed')
            ->count())->toBe(0);
});

test('the closing entry for a year with activity balances and totals the whole year movement', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    $year = (int) now()->year;

    app(LedgerPostingService::class)->post($business->id, [
        ['account_key' => 'cash', 'debit' => 500000],
        ['account_key' => 'sales_income', 'credit' => 500000],
    ], ['idempotency_key' => 'verify:year_close:income', 'date' => "{$year}-03-15"]);

    app(LedgerPostingService::class)->post($business->id, [
        ['account_key' => 'default_expense', 'debit' => 200000],
        ['account_key' => 'cash', 'credit' => 200000],
    ], ['idempotency_key' => 'verify:year_close:expense', 'date' => "{$year}-04-10"]);

    $this->withToken(yearEndCloseToken($owner))
        ->postJson("/api/v1/management/accounting/settings/years/{$year}/close")
        ->assertOk()
        ->assertJsonPath('data.fiscal_year.status', 'closed');

    $entry = JournalEntry::query()
        ->where('business_id', $business->id)
        ->where('idempotency_key', "year_close:{$year}")
        ->firstOrFail();

    // The legacy test asserted isBalanced() and totalDebits()/totalCredits()
    // == 500000 directly on the closing entry. ws37 pins each expected line
    // but never the totals or that no extra lines slipped in, so restore the
    // exact-once assertion here (income zeroed 500,000, expense zeroed
    // 200,000, 300,000 net to retained earnings).
    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->lines()->count())->toBe(3)
        ->and($entry->totalDebits())->toBe(500000)
        ->and($entry->totalCredits())->toBe(500000);
});
