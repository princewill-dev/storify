<?php

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\LedgerSetupService;

use function Pest\Laravel\actingAs;

function journalOwner(): array
{
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    return [$owner, $business];
}

test('journal entries can be saved as drafts and posted later', function () {
    [$owner, $business] = journalOwner();

    $cash = LedgerAccount::where('business_id', $business->id)->where('subtype', 'cash')->value('id');
    $sales = LedgerAccount::where('business_id', $business->id)->where('subtype', 'sales_income')->value('id');

    actingAs($owner)->post(route('management.accounting.journal.store'), [
        'entry_date' => now()->toDateString(),
        'memo' => 'Draft entry',
        'save_as_draft' => 1,
        'lines' => [
            ['ledger_account_id' => $cash, 'debit' => 100, 'credit' => null],
            ['ledger_account_id' => $sales, 'debit' => null, 'credit' => 50],
        ],
    ])->assertRedirect();

    $entry = JournalEntry::where('business_id', $business->id)->where('memo', 'Draft entry')->sole();

    expect($entry->status)->toBe('draft')
        ->and($entry->lines)->toHaveCount(2);

    // Posting an unbalanced draft is rejected
    actingAs($owner)->post(route('management.accounting.journal.post', $entry))
        ->assertSessionHas('error');

    expect($entry->fresh()->status)->toBe('draft');

    // Balance the draft then post it
    $entry->lines()->where('credit_kobo', 5000)->update(['credit_kobo' => 10000]);

    actingAs($owner)->post(route('management.accounting.journal.post', $entry))
        ->assertSessionHasNoErrors();

    $entry->refresh();

    expect($entry->status)->toBe('posted')
        ->and($entry->posted_at)->not->toBeNull()
        ->and($entry->fiscal_period_id)->not->toBeNull();
});

test('draft journal entries can be deleted', function () {
    [$owner, $business] = journalOwner();

    $cash = LedgerAccount::where('business_id', $business->id)->where('subtype', 'cash')->value('id');
    $sales = LedgerAccount::where('business_id', $business->id)->where('subtype', 'sales_income')->value('id');

    actingAs($owner)->post(route('management.accounting.journal.store'), [
        'entry_date' => now()->toDateString(),
        'memo' => 'Draft to delete',
        'save_as_draft' => 1,
        'lines' => [
            ['ledger_account_id' => $cash, 'debit' => 100, 'credit' => null],
            ['ledger_account_id' => $sales, 'debit' => null, 'credit' => 50],
        ],
    ]);

    $entry = JournalEntry::where('business_id', $business->id)->where('memo', 'Draft to delete')->sole();

    actingAs($owner)->delete(route('management.accounting.journal.destroy', $entry))
        ->assertRedirect(route('management.accounting.journal.index'));

    expect(JournalEntry::find($entry->id))->toBeNull()
        ->and($entry->lines()->count())->toBe(0);
});

test('posted entries cannot be deleted', function () {
    [$owner, $business] = journalOwner();

    $cash = LedgerAccount::where('business_id', $business->id)->where('subtype', 'cash')->value('id');
    $sales = LedgerAccount::where('business_id', $business->id)->where('subtype', 'sales_income')->value('id');

    actingAs($owner)->post(route('management.accounting.journal.store'), [
        'entry_date' => now()->toDateString(),
        'memo' => 'Posted entry',
        'lines' => [
            ['ledger_account_id' => $cash, 'debit' => 100, 'credit' => null],
            ['ledger_account_id' => $sales, 'debit' => null, 'credit' => 100],
        ],
    ]);

    $entry = JournalEntry::where('business_id', $business->id)->where('memo', 'Posted entry')->sole();

    actingAs($owner)->delete(route('management.accounting.journal.destroy', $entry))
        ->assertSessionHas('error');

    expect(JournalEntry::find($entry->id))->not->toBeNull();
});

test('financial statements can be exported as pdf', function () {
    [$owner] = journalOwner();

    actingAs($owner)
        ->get(route('management.accounting.reports.trial-balance', ['export' => 'pdf']))
        ->assertOk();

    actingAs($owner)
        ->get(route('management.accounting.reports.profit-and-loss', ['export' => 'pdf']))
        ->assertOk();

    actingAs($owner)
        ->get(route('management.accounting.reports.balance-sheet', ['export' => 'pdf']))
        ->assertOk();
});
