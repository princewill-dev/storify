<?php

use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;

use function Pest\Laravel\actingAs;

test('platform accounting pages render for a superadmin', function () {
    $admin = User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    app(LedgerSetupService::class)->ensureForBusiness(null);

    actingAs($admin);

    $this->get(route('admin.accounting.index'))->assertOk();
    $this->get(route('admin.accounting.accounts'))->assertOk();
    $this->get(route('admin.accounting.journal'))->assertOk();
    $this->get(route('admin.accounting.reports'))->assertOk();
    $this->get(route('admin.accounting.reports', ['report' => 'balance-sheet']))->assertOk();
    $this->get(route('admin.accounting.reports', ['report' => 'trial-balance']))->assertOk();
    $this->get(route('admin.accounting.settings'))->assertOk();
});

test('a tenant journal entry is not visible on platform books', function () {
    $admin = User::factory()->create([
        'role' => 'superadmin',
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    [$owner, $business] = createBusinessOwner();
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    $entry = app(LedgerPostingService::class)->post($business->id, [
        ['account_key' => 'cash', 'debit' => 1000],
        ['account_key' => 'sales_income', 'credit' => 1000],
    ], ['idempotency_key' => 'test:tenant:1']);

    actingAs($admin);
    $this->get(route('admin.accounting.journal.show', $entry))->assertNotFound();
});

test('subscription payments post to platform books', function () {
    [$owner, $business] = createBusinessOwner();

    $payment = Payment::create([
        'business_id' => $business->id,
        'user_id' => $owner->id,
        'reference' => 'ref_platform_test_1',
        'amount' => 15000,
        'currency' => 'NGN',
        'status' => Payment::STATUS_SUCCESS,
        'payment_type' => Payment::TYPE_SUBSCRIPTION,
        'paid_at' => now(),
    ]);

    $entry = app(LedgerPostingService::class)->postSubscriptionPayment($payment, $owner->id);

    expect($entry)->not->toBeNull()
        ->and($entry->business_id)->toBeNull()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebits())->toBe(1500000);

    $this->assertDatabaseHas('journal_entries', [
        'id' => $entry->id,
        'business_id' => null,
        'idempotency_key' => 'subscription_payment:'.$payment->id,
    ]);

    // Idempotent
    $again = app(LedgerPostingService::class)->postSubscriptionPayment($payment->fresh(), $owner->id);
    expect($again->id)->toBe($entry->id)
        ->and(JournalEntry::where('idempotency_key', 'subscription_payment:'.$payment->id)->count())->toBe(1);
});
