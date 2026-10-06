<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Accounting\LedgerSetupService;

/*
|--------------------------------------------------------------------------
| WS-23 — Journal draft lifecycle (ported from the legacy Blade suite)
|--------------------------------------------------------------------------
|
| tests/Feature/Accounting/JournalDraftTest.php exercised the draft → post →
| delete workflow through the legacy web routes. The API equivalents mostly
| live in ws23coajournalTest.php ('a manual entry saves as a draft, posts
| later and deletes only while a draft') and the PDF export is pinned more
| strongly by ws24accountingreportsTest.php, so those parts are not repeated.
|
| This file pins the legs those tests left unasserted: the lines a draft
| persists, the fiscal period stamped when a *draft* is posted (the direct
| post path is pinned in ws23coajournalTest.php, the draft path was not),
| and the journal line rows left behind by both delete outcomes.
*/

function ws23jdToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws23jdEnsureBooks(int $businessId): void
{
    app(LedgerSetupService::class)->ensureForBusiness($businessId);
}

function ws23jdAccount(int $businessId, string $code): LedgerAccount
{
    return LedgerAccount::query()
        ->where('business_id', $businessId)
        ->where('code', $code)
        ->firstOrFail();
}

test('a saved draft keeps its lines and stays unposted', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23jdEnsureBooks($business->id);

    $cash = ws23jdAccount($business->id, '1010');
    $sales = ws23jdAccount($business->id, '4010');

    $response = $this->withToken(ws23jdToken($owner))->postJson('/api/v1/management/accounting/journal', [
        'entry_date' => now()->toDateString(),
        'memo' => 'Draft entry',
        'save_as_draft' => true,
        'lines' => [
            ['ledger_account_id' => $cash->id, 'debit_kobo' => 10000, 'credit_kobo' => null],
            ['ledger_account_id' => $sales->id, 'debit_kobo' => null, 'credit_kobo' => 5000],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.entry.status', 'draft')
        ->assertJsonPath('data.entry.memo', 'Draft entry')
        ->assertJsonPath('data.entry.total_debits', 10000)
        ->assertJsonPath('data.entry.total_credits', 5000)
        ->assertJsonPath('data.entry.is_balanced', false);

    // Both legs come back as integer kobo on the saved draft.
    $lines = collect($response->json('data.entry.lines'))->keyBy('account_code');

    expect($lines)->toHaveCount(2)
        ->and($lines['1010']['debit'])->toBe(10000)
        ->and($lines['1010']['credit'])->toBe(0)
        ->and($lines['4010']['debit'])->toBe(0)
        ->and($lines['4010']['credit'])->toBe(5000);

    $entry = JournalEntry::query()
        ->where('business_id', $business->id)
        ->where('memo', 'Draft entry')
        ->sole();

    expect($entry->status)->toBe(JournalEntry::STATUS_DRAFT)
        ->and(JournalLine::where('journal_entry_id', $entry->id)->count())->toBe(2)
        ->and($entry->posted_at)->toBeNull()
        ->and($entry->fiscal_period_id)->toBeNull();
});

test('posting a draft stamps the open fiscal period and the poster', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23jdEnsureBooks($business->id);

    $cash = ws23jdAccount($business->id, '1010');
    $rent = ws23jdAccount($business->id, '5200');

    $token = ws23jdToken($owner);

    $draftId = $this->withToken($token)->postJson('/api/v1/management/accounting/journal', [
        'entry_date' => now()->toDateString(),
        'memo' => 'Balanced draft',
        'save_as_draft' => true,
        'lines' => [
            ['ledger_account_id' => $rent->id, 'debit_kobo' => 10000],
            ['ledger_account_id' => $cash->id, 'credit_kobo' => 10000],
        ],
    ])->assertCreated()->json('data.entry.id');

    $this->withToken($token)
        ->postJson("/api/v1/management/accounting/journal/{$draftId}/post")
        ->assertOk()
        ->assertJsonPath('data.entry.status', 'posted')
        ->assertJsonPath('data.entry.is_balanced', true)
        ->assertJsonPath('data.entry.fiscal_period', now()->format('Y-m'))
        ->assertJsonPath('data.entry.posted_by', $owner->name)
        ->assertJsonPath('data.entry.posted_at', fn ($at) => is_string($at) && $at !== '');

    $entry = JournalEntry::findOrFail($draftId);

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->posted_at)->not->toBeNull()
        ->and($entry->fiscal_period_id)->not->toBeNull();
});

test('deleting a draft removes its journal lines', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23jdEnsureBooks($business->id);

    $cash = ws23jdAccount($business->id, '1010');
    $sales = ws23jdAccount($business->id, '4010');

    $token = ws23jdToken($owner);

    $draftId = $this->withToken($token)->postJson('/api/v1/management/accounting/journal', [
        'entry_date' => now()->toDateString(),
        'memo' => 'Draft to delete',
        'save_as_draft' => true,
        'lines' => [
            ['ledger_account_id' => $cash->id, 'debit_kobo' => 10000],
            ['ledger_account_id' => $sales->id, 'credit_kobo' => 5000],
        ],
    ])->assertCreated()->json('data.entry.id');

    expect(JournalLine::where('journal_entry_id', $draftId)->count())->toBe(2);

    $this->withToken($token)
        ->deleteJson("/api/v1/management/accounting/journal/{$draftId}")
        ->assertOk();

    expect(JournalEntry::find($draftId))->toBeNull()
        ->and(JournalLine::where('journal_entry_id', $draftId)->count())->toBe(0);

    // The deleted draft also drops out of the searchable list.
    $this->withToken($token)
        ->getJson('/api/v1/management/accounting/journal?q='.urlencode('Draft to delete'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a posted entry refuses deletion and keeps its lines', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws23jdEnsureBooks($business->id);

    $cash = ws23jdAccount($business->id, '1010');
    $sales = ws23jdAccount($business->id, '4010');

    $token = ws23jdToken($owner);

    $postedId = $this->withToken($token)->postJson('/api/v1/management/accounting/journal', [
        'entry_date' => now()->toDateString(),
        'memo' => 'Posted entry',
        'lines' => [
            ['ledger_account_id' => $cash->id, 'debit_kobo' => 10000],
            ['ledger_account_id' => $sales->id, 'credit_kobo' => 10000],
        ],
    ])->assertCreated()->json('data.entry.id');

    $this->withToken($token)
        ->deleteJson("/api/v1/management/accounting/journal/{$postedId}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only draft entries can be deleted. Use reversal for posted entries.');

    $entry = JournalEntry::find($postedId);

    expect($entry)->not->toBeNull()
        ->and($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and(JournalLine::where('journal_entry_id', $postedId)->count())->toBe(2);
});
