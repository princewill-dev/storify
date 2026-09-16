<?php

use App\Models\Business;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Accounting\LedgerSetupService;

function ledgerContext(): array
{
    [$owner, $business] = createBusinessOwner();

    $store = Store::create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'name' => 'Ledger Store',
        'status' => Store::STATUS_ACTIVE,
    ]);

    $posting = app(LedgerPostingService::class);
    app(LedgerSetupService::class)->ensureForBusiness($business->id);

    return [$owner, $business, $store, $posting];
}

test('it posts a balanced journal entry', function () {
    [, $business, , $posting] = ledgerContext();

    $entry = $posting->post($business->id, [
        ['account_key' => 'cash', 'debit' => 250000],
        ['account_key' => 'sales_income', 'credit' => 250000],
    ], ['memo' => 'Test entry', 'idempotency_key' => 'test:1']);

    expect($entry)->not->toBeNull()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebits())->toBe(250000)
        ->and($entry->totalCredits())->toBe(250000)
        ->and($entry->lines)->toHaveCount(2);
});

test('it rejects an unbalanced journal entry', function () {
    [, $business, , $posting] = ledgerContext();

    $posting->post($business->id, [
        ['account_key' => 'cash', 'debit' => 100],
        ['account_key' => 'sales_income', 'credit' => 200],
    ]);
})->throws(RuntimeException::class);

test('it is idempotent for the same key', function () {
    [, $business, , $posting] = ledgerContext();

    $first = $posting->post($business->id, [
        ['account_key' => 'cash', 'debit' => 1000],
        ['account_key' => 'sales_income', 'credit' => 1000],
    ], ['idempotency_key' => 'test:2']);

    $second = $posting->post($business->id, [
        ['account_key' => 'cash', 'debit' => 1000],
        ['account_key' => 'sales_income', 'credit' => 1000],
    ], ['idempotency_key' => 'test:2']);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::where('idempotency_key', 'test:2')->count())->toBe(1);
});

test('it refuses to post into a closed fiscal period', function () {
    [, $business, , $posting] = ledgerContext();

    $date = now()->toDateString();
    FiscalPeriod::where('business_id', $business->id)
        ->where('name', now()->format('Y-m'))
        ->update(['status' => 'closed']);

    $posting->post($business->id, [
        ['account_key' => 'cash', 'debit' => 1000],
        ['account_key' => 'sales_income', 'credit' => 1000],
    ], ['date' => $date]);
})->throws(RuntimeException::class);

test('opening balances plug into opening balance equity', function () {
    [, $business, , $posting] = ledgerContext();

    $entry = $posting->postOpeningBalances($business->id, [
        'cash' => 100000,
        'bank' => 250000,
        'accounts_payable' => -50000,
    ], now()->toDateString());

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebits())->toBe(350000);

    $equityLine = $entry->lines()
        ->whereHas('account', fn ($q) => $q->where('subtype', 'opening_balance_equity'))
        ->first();

    expect($equityLine)->not->toBeNull()
        ->and($equityLine->credit_kobo)->toBe(300000);
});

test('setup creates chart of accounts mappings and periods idempotently', function () {
    [, $business] = ledgerContext();

    $setup = app(LedgerSetupService::class);
    $setup->ensureForBusiness($business->id);

    expect(LedgerAccount::where('business_id', $business->id)->count())->toBe(25)
        ->and(LedgerMapping::where('business_id', $business->id)->count())->toBe(20)
        ->and(FiscalPeriod::where('business_id', $business->id)->count())->toBe(12);
});

test('platform books can be posted independently of tenant books', function () {
    $setup = app(LedgerSetupService::class);
    $posting = app(LedgerPostingService::class);

    $setup->ensureForBusiness(null);

    $entry = $posting->post(null, [
        ['account_key' => 'gateway_clearing', 'debit' => 500000],
        ['account_key' => 'sales_income', 'credit' => 500000],
    ], ['idempotency_key' => 'test:platform:1']);

    expect($entry)->not->toBeNull()
        ->and($entry->business_id)->toBeNull()
        ->and($entry->isBalanced())->toBeTrue()
        ->and(LedgerAccount::whereNull('business_id')->count())->toBe(25);
});

test('a pos sale posts a balanced ledger entry with revenue split', function () {
    [$owner, $business, $store, $posting] = ledgerContext();

    $product = Product::create([
        'store_id' => $store->id,
        'business_id' => $business->id,
        'name' => 'Ledger Product',
        'quantity' => 5,
        'stock_quantity' => 5,
        'amount' => 2000,
        'cost_price' => 1200,
        'average_cost_kobo' => 120000,
        'status' => 'active',
    ]);

    $order = App\Models\Order::create([
        'business_id' => $business->id,
        'store_id' => $store->id,
        'user_id' => $owner->id,
        'source' => 'pos',
        'order_number' => 'POS-TEST-1',
        'subtotal' => 2000,
        'total' => 2000,
        'amount_paid' => 2000,
        'status' => 'completed',
    ]);

    App\Models\OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 2000,
        'quantity' => 1,
        'subtotal' => 2000,
        'cost_kobo' => 120000,
    ]);

    App\Models\Transaction::create([
        'reference' => 'TXN-POS-TEST1',
        'order_id' => $order->id,
        'business_id' => $business->id,
        'amount' => 2000,
        'status' => App\Enums\TransactionStatus::CONFIRMED,
        'paid_at' => now(),
        'metadata' => ['leg_method' => 'cash'],
    ]);

    $entry = $posting->postSale($order->fresh(['items', 'transactions']), $owner->id);

    expect($entry)->not->toBeNull()
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->totalDebits())->toBe(320000); // 2000 cash + 1200 COGS in kobo

    $cash = $entry->lines()->whereHas('account', fn ($q) => $q->where('subtype', 'cash'))->first();
    $sales = $entry->lines()->whereHas('account', fn ($q) => $q->where('subtype', 'sales_income'))->first();
    $cogs = $entry->lines()->whereHas('account', fn ($q) => $q->where('subtype', 'cogs'))->first();

    expect($cash->debit_kobo)->toBe(200000)
        ->and($sales->credit_kobo)->toBe(200000)
        ->and($cogs->debit_kobo)->toBe(120000);
});
