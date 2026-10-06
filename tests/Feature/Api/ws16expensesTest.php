<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\LedgerSetupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| WS-16 — Accounting: Expenses
|--------------------------------------------------------------------------
*/

function ws16Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

/** Bootstrap the default chart of accounts + fiscal periods for a business. */
function ws16EnsureBooks(int $businessId): void
{
    app(LedgerSetupService::class)->ensureForBusiness($businessId);
}

function ws16Account(int $businessId, string $code): LedgerAccount
{
    return LedgerAccount::query()
        ->where('business_id', $businessId)
        ->where('code', $code)
        ->firstOrFail();
}

function ws16Category(int $businessId, string $name): ExpenseCategory
{
    return ExpenseCategory::query()
        ->where('business_id', $businessId)
        ->where('name', $name)
        ->firstOrFail();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ws16ExpenseRow(int $businessId, int $accountId, array $attributes = []): Expense
{
    $amount = $attributes['amount_kobo'] ?? 100000;
    $tax = $attributes['tax_kobo'] ?? 0;

    return Expense::create(array_merge([
        'business_id' => $businessId,
        'ledger_account_id' => $accountId,
        'expense_date' => now()->toDateString(),
        'amount_kobo' => $amount,
        'tax_kobo' => $tax,
        'total_kobo' => $amount + $tax,
        'currency' => 'NGN',
        'payment_method' => 'cash',
        'status' => Expense::STATUS_PAID,
    ], $attributes));
}

test('the expense list returns the legacy stats block and applies category, date and status filters', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    $rent = ws16Account($business->id, '5200');
    $utilities = ws16Account($business->id, '5300');

    $thisMonth = ws16ExpenseRow($business->id, $rent->id, [
        'expense_category_id' => ws16Category($business->id, 'Rent')->id,
        'amount_kobo' => 500000,
        'description' => 'October rent',
        'reference' => 'INV-1',
    ]);
    $void = ws16ExpenseRow($business->id, $utilities->id, [
        'expense_category_id' => ws16Category($business->id, 'Utilities')->id,
        'amount_kobo' => 300000,
        'description' => 'October power',
        'status' => Expense::STATUS_VOID,
    ]);
    $lastYear = ws16ExpenseRow($business->id, $rent->id, [
        'expense_date' => now()->subYear()->toDateString(),
        'amount_kobo' => 200000,
        'description' => 'Last year rent',
    ]);

    // Another business's rows must never appear.
    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($otherBusiness->id);
    ws16ExpenseRow($otherBusiness->id, ws16Account($otherBusiness->id, '5200')->id, ['amount_kobo' => 999999]);

    $base = '/api/v1/management/accounting/expenses';
    $token = ws16Token($owner);

    $this->withToken($token)->getJson($base)
        ->assertOk()
        ->assertJsonPath('meta.stats.month_kobo', 500000)
        ->assertJsonPath('meta.stats.year_kobo', 500000)
        ->assertJsonPath('meta.stats.records', 3)
        ->assertJsonCount(3, 'data');

    // Rows expose the legacy fallback: no category → the ledger account name.
    $byDescription = collect($this->withToken($token)->getJson($base)->json('data'))
        ->keyBy('description');
    expect($byDescription['Last year rent']['category_label'])->toBe('Rent');

    // The category filter is a strict `expense_category_id` match, as legacy's
    // was — the fallback to the ledger account name is display-only, so the
    // uncategorised "Last year rent" row stays out even though it sits on the
    // Rent account.
    $this->withToken($token)->getJson($base.'?category='.ws16Category($business->id, 'Rent')->id)
        ->assertOk()
        ->assertJsonPath('meta.stats.records', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $thisMonth->id);

    $this->withToken($token)->getJson($base.'?status=void')
        ->assertOk()
        ->assertJsonPath('meta.stats.records', 1)
        ->assertJsonPath('data.0.id', $void->id);

    $this->withToken($token)->getJson($base.'?from='.now()->toDateString().'&to='.now()->toDateString())
        ->assertOk()
        ->assertJsonPath('meta.stats.records', 2)
        ->assertJsonCount(2, 'data');

    $this->withToken($token)->getJson($base.'?from='.now()->toDateString())
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['description' => $lastYear->description]);

    // Legacy's month/year cards ignore the filters; Records respects them.
    $this->withToken($token)->getJson($base.'?status=void')
        ->assertJsonPath('meta.stats.month_kobo', 500000)
        ->assertJsonPath('meta.stats.year_kobo', 500000);
});

test('recording an expense stores the receipt and posts a balanced entry with the VAT split', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    $rent = ws16Account($business->id, '5200');
    $bank = ws16Account($business->id, '1020');
    $vatPayable = ws16Account($business->id, '2100');

    $response = $this->withToken(ws16Token($owner))
        ->withHeader('Accept', 'application/json')
        ->post('/api/v1/management/accounting/expenses', [
            'expense_date' => now()->toDateString(),
            'ledger_account_id' => $rent->id,
            'amount' => '5000.00',
            'tax' => '375',
            'payment_method' => 'bank_transfer',
            'payment_account_id' => $bank->id,
            'reference' => 'INV-500',
            'description' => 'Office rent',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.expense.status', 'paid')
        ->assertJsonPath('data.expense.amount_kobo', 500000)
        ->assertJsonPath('data.expense.tax_kobo', 37500)
        ->assertJsonPath('data.expense.total_kobo', 537500)
        ->assertJsonPath('data.expense.has_receipt', true)
        ->assertJsonMissingPath('data.posting_warning');

    $expense = Expense::firstOrFail();

    expect($expense->receipt_path)->not->toBeNull();
    expect(Storage::disk('public')->exists($expense->receipt_path))->toBeTrue();
    expect($expense->journal_entry_id)->not->toBeNull();

    $entry = JournalEntry::findOrFail($expense->journal_entry_id);

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($entry->idempotency_key)->toBe('expense:'.$expense->id);

    $lines = $entry->lines()->get()->keyBy('ledger_account_id');

    expect((int) $entry->lines()->sum('debit_kobo'))->toBe(537500);
    expect((int) $entry->lines()->sum('credit_kobo'))->toBe(537500);
    expect((int) $lines[$rent->id]->debit_kobo)->toBe(500000);
    expect((int) $lines[$vatPayable->id]->debit_kobo)->toBe(37500);
    expect((int) $lines[$bank->id]->credit_kobo)->toBe(537500);

    // Detail carries the details grid + journal trace + receipt rendering.
    $this->withToken(ws16Token($owner))
        ->getJson('/api/v1/management/accounting/expenses/'.$expense->id)
        ->assertOk()
        ->assertJsonPath('data.expense.description', 'Office rent')
        ->assertJsonPath('data.expense.payment_method_label', 'Bank Transfer')
        ->assertJsonPath('data.expense.receipt.is_pdf', false)
        ->assertJsonPath('data.expense.journal_entry.status', 'posted')
        ->assertJsonCount(3, 'data.expense.journal_entry.lines')
        ->assertJsonPath('data.expense.reversal_entry', null);
});

test('recording an expense validates the legacy rules and refuses another business accounts', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    $token = ws16Token($owner);
    $url = '/api/v1/management/accounting/expenses';

    $this->withToken($token)->postJson($url, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expense_date', 'ledger_account_id', 'amount', 'payment_method']);

    $this->withToken($token)->postJson($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($business->id, '5200')->id,
        'amount' => 1000,
        'payment_method' => 'crypto',
    ])->assertStatus(422)->assertJsonValidationErrors('payment_method');

    $this->withToken($token)->withHeader('Accept', 'application/json')->post($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($business->id, '5200')->id,
        'amount' => 1000,
        'payment_method' => 'cash',
        'receipt' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
    ])->assertStatus(422)->assertJsonValidationErrors('receipt');

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($otherBusiness->id);

    $this->withToken($token)->postJson($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($otherBusiness->id, '5200')->id,
        'payment_account_id' => ws16Account($otherBusiness->id, '1020')->id,
        'expense_category_id' => ws16Category($otherBusiness->id, 'Rent')->id,
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['ledger_account_id', 'payment_account_id', 'expense_category_id']);

    // A deactivated account is refused even though it exists.
    $rent = ws16Account($business->id, '5200');
    $rent->update(['is_active' => false]);

    $this->withToken($token)->postJson($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => $rent->id,
        'amount' => 1000,
        'payment_method' => 'cash',
    ])->assertStatus(422)->assertJsonValidationErrors('ledger_account_id');
});

test('another business expense is not reachable, voidable or deletable', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    [$otherOwner, $otherBusiness] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($otherBusiness->id);

    $theirs = ws16ExpenseRow($otherBusiness->id, ws16Account($otherBusiness->id, '5200')->id);
    $token = ws16Token($owner);

    $this->withToken($token)->getJson('/api/v1/management/accounting/expenses/'.$theirs->id)->assertStatus(403);
    $this->withToken($token)->postJson('/api/v1/management/accounting/expenses/'.$theirs->id.'/void')->assertStatus(403);
    $this->withToken($token)->deleteJson('/api/v1/management/accounting/expenses/'.$theirs->id)->assertStatus(403);

    expect($theirs->fresh()->status)->toBe(Expense::STATUS_PAID);
});

test('voiding posts a reversal, flips the expense and refuses a double void', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    $token = ws16Token($owner);
    $url = '/api/v1/management/accounting/expenses';

    $this->withToken($token)->postJson($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($business->id, '5200')->id,
        'amount' => 2500,
        'payment_method' => 'cash',
        'description' => 'Void me',
    ])->assertCreated();

    $expense = Expense::firstOrFail();
    $original = JournalEntry::findOrFail($expense->journal_entry_id);

    $this->withToken($token)->postJson($url.'/'.$expense->id.'/void')
        ->assertOk()
        ->assertJsonPath('data.expense.status', 'void')
        ->assertJsonPath('data.expense.journal_entry.status', 'void');

    $original->refresh();

    expect($original->status)->toBe(JournalEntry::STATUS_VOID);
    expect($original->voided_at)->not->toBeNull();

    $reversal = JournalEntry::where('reversal_of_id', $original->id)->firstOrFail();

    expect($reversal->idempotency_key)->toBe('reversal:'.$original->id);
    expect((int) $reversal->lines()->sum('credit_kobo'))->toBe(250000);
    expect((int) $reversal->lines()->sum('debit_kobo'))->toBe(250000);

    // The expense detail now links back to its reversal (an improvement over
    // legacy, which only showed the banner on the reversal entry).
    $this->withToken($token)->getJson($url.'/'.$expense->id)
        ->assertOk()
        ->assertJsonPath('data.expense.reversal_entry.id', $reversal->id);

    $this->withToken($token)->postJson($url.'/'.$expense->id.'/void')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This expense is already void.');
});

test('posted expenses refuse delete while unposted ones delete with their receipt', function () {
    Storage::fake('public');

    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    $token = ws16Token($owner);
    $url = '/api/v1/management/accounting/expenses';

    // Posted → refused with the legacy message.
    $this->withToken($token)->postJson($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($business->id, '5200')->id,
        'amount' => 1200,
        'payment_method' => 'cash',
    ])->assertCreated();

    $posted = Expense::firstOrFail();

    $this->withToken($token)->deleteJson($url.'/'.$posted->id)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Posted expenses cannot be deleted. Void it instead.');

    expect(Expense::find($posted->id))->not->toBeNull();

    // Close the period so posting fails and the row stays unposted.
    FiscalPeriod::where('business_id', $business->id)
        ->where('name', now()->format('Y-m'))
        ->update(['status' => 'closed']);

    $this->withToken($token)->withHeader('Accept', 'application/json')->post($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($business->id, '5200')->id,
        'amount' => 800,
        'payment_method' => 'cash',
        'description' => 'Unposted',
        'receipt' => UploadedFile::fake()->image('receipt.png'),
    ])->assertCreated()
        ->assertJsonPath('data.expense.journal_entry_id', null)
        ->assertJsonPath('data.expense.has_receipt', true);

    $unposted = Expense::where('description', 'Unposted')->firstOrFail();
    $receiptPath = $unposted->receipt_path;

    expect($receiptPath)->not->toBeNull();
    expect(Storage::disk('public')->exists($receiptPath))->toBeTrue();

    $this->withToken($token)->deleteJson($url.'/'.$unposted->id)->assertOk();

    expect(Expense::find($unposted->id))->toBeNull();
    // The orphaned receipt is cleaned up too (legacy left it behind).
    expect(Storage::disk('public')->exists($receiptPath))->toBeFalse();
});

test('a posting failure warns without losing the expense row', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    FiscalPeriod::where('business_id', $business->id)
        ->where('name', now()->format('Y-m'))
        ->update(['status' => 'closed']);

    $response = $this->withToken(ws16Token($owner))->postJson('/api/v1/management/accounting/expenses', [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($business->id, '5200')->id,
        'amount' => 900,
        'payment_method' => 'cash',
        'description' => 'Saved despite the ledger',
    ]);

    $response->assertCreated()->assertJsonPath('data.expense.status', 'paid');

    expect($response->json('data.posting_warning'))->toContain('ledger posting failed');

    $expense = Expense::firstOrFail();

    expect($expense->journal_entry_id)->toBeNull();
    expect(JournalEntry::count())->toBe(0);
});

test('expense endpoints require the legacy accounting permissions', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    setPermissionsTeamId($business->id);
    $staff->assignRole('Store Manager');

    $existing = ws16ExpenseRow($business->id, ws16Account($business->id, '5200')->id);

    $url = '/api/v1/management/accounting/expenses';
    $token = ws16Token($staff);

    // The shared route file's read gate is "accounting view".
    $this->withToken($token)->getJson($url)->assertStatus(403);
    $this->withToken($token)->getJson($url.'/'.$existing->id)->assertStatus(403);

    // Writes are gated on "accounting expenses".
    $this->withToken($token)->postJson($url, [
        'expense_date' => now()->toDateString(),
        'ledger_account_id' => ws16Account($business->id, '5200')->id,
        'amount' => 100,
        'payment_method' => 'cash',
    ])->assertStatus(403);

    // The owner (all permissions) can read and record. The Sanctum request
    // guard caches the first user it resolves for the whole test case, so the
    // identity has to be dropped when a different token takes over.
    $this->app['auth']->forgetGuards();

    $this->withToken(ws16Token($owner))->getJson($url)->assertOk();
});

test('the expense options endpoint only offers active picker rows', function () {
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);
    ws16EnsureBooks($business->id);

    $rent = ws16Account($business->id, '5200');
    $rent->update(['is_active' => false]);

    Supplier::create(['business_id' => $business->id, 'name' => 'Acme Ltd', 'is_active' => true]);
    Supplier::create(['business_id' => $business->id, 'name' => 'Dormant Ltd', 'is_active' => false]);

    $response = $this->withToken(ws16Token($owner))
        ->getJson('/api/v1/management/accounting/expense-options')
        ->assertOk();

    $accountCodes = array_column($response->json('data.expense_accounts'), 'code');
    expect($accountCodes)->not->toContain('5200');
    expect($accountCodes)->toContain('5900');

    $paymentNames = array_column($response->json('data.payment_accounts'), 'name');
    expect($paymentNames)->toContain('Cash on Hand');
    expect($paymentNames)->toContain('Bank');
    expect($paymentNames)->not->toContain('VAT Payable');

    $supplierNames = array_column($response->json('data.suppliers'), 'name');
    expect($supplierNames)->toBe(['Acme Ltd']);

    $categoryNames = array_column($response->json('data.categories'), 'name');
    expect($categoryNames)->toContain('Rent');
    expect($categoryNames)->toContain('Utilities');

    expect(array_column($response->json('data.payment_methods'), 'value'))
        ->toBe(['cash', 'bank_transfer', 'card', 'cheque', 'other']);
});
