<?php

use App\Http\Controllers\Api\V1\Management\AccountingController;
use App\Http\Controllers\Api\V1\Management\CategoryController;
use App\Http\Controllers\Api\V1\Management\CustomerController;
use App\Http\Controllers\Api\V1\Management\DashboardController;
use App\Http\Controllers\Api\V1\Management\OrderController;
use App\Http\Controllers\Api\V1\Management\ProductController;
use App\Http\Controllers\Api\V1\Management\RoleController;
use App\Http\Controllers\Api\V1\Management\SearchController;
use App\Http\Controllers\Api\V1\Management\StaffController;
use App\Http\Controllers\Api\V1\Management\StoreController;
use App\Http\Controllers\Api\V1\Management\TransactionController;
use App\Http\Controllers\Api\V1\Management\WarehouseController;
use App\Http\Middleware\EnsurePosStoreAccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Management app API (business dashboard)
|--------------------------------------------------------------------------
| Bearer tokens, audience "management", Spatie team context + permissions.
| The frontend gates onboarding/subscription using the `next` field returned
| by the auth endpoints.
*/

Route::middleware(['auth:sanctum', 'token.audience:management', 'team.context'])
    ->prefix('management')
    ->name('api.management.')
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->middleware('permission:dashboard view')
            ->name('dashboard');

        Route::get('search', SearchController::class)->name('search');

        // Stores
        Route::middleware('permission:stores view')->group(function () {
            Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
            Route::get('stores/{store}', [StoreController::class, 'show'])
                ->middleware(EnsurePosStoreAccess::class)
                ->name('stores.show');
        });

        // Inventory
        Route::middleware('permission:warehouses view')->group(function () {
            Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
            Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show');
        });

        Route::middleware('permission:warehouses create')->group(function () {
            Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        });

        Route::middleware('permission:warehouses edit')->group(function () {
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        });

        Route::middleware('permission:warehouses delete')->group(function () {
            Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
        });

        // Catalog
        Route::middleware('permission:products view')->group(function () {
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        });

        Route::middleware('permission:products create')->group(function () {
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        });

        Route::middleware('permission:products edit')->group(function () {
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::put('products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        });

        Route::middleware('permission:products delete')->group(function () {
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        });

        // Orders
        Route::middleware('permission:orders view')->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        });

        Route::middleware('permission:orders status_update')->group(function () {
            Route::put('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
            Route::put('orders/{order}/payment-status', [OrderController::class, 'updatePaymentStatus'])->name('orders.payment-status');
        });

        Route::middleware('permission:orders delete')->group(function () {
            Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
        });

        // Customers
        Route::middleware('permission:customers view')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        });

        Route::middleware('permission:customers edit')->group(function () {
            Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        });

        Route::middleware('permission:customers suspend')->group(function () {
            Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
            Route::post('customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');
        });

        // Transactions
        Route::middleware('permission:transactions view')->group(function () {
            Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
            Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        });

        Route::middleware('permission:transactions confirm')->group(function () {
            Route::post('transactions/{transaction}/confirm', [TransactionController::class, 'confirm'])->name('transactions.confirm');
        });

        Route::middleware('permission:transactions reject')->group(function () {
            Route::post('transactions/{transaction}/reject', [TransactionController::class, 'reject'])->name('transactions.reject');
        });

        Route::middleware('permission:transactions refund')->group(function () {
            Route::post('transactions/{transaction}/refund', [TransactionController::class, 'refund'])->name('transactions.refund');
        });

        // Staff & roles
        Route::middleware('permission:staff view')->group(function () {
            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::get('staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        });

        Route::middleware('permission:staff create')->group(function () {
            Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        });

        Route::middleware('permission:staff edit')->group(function () {
            Route::put('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        });

        Route::middleware('permission:staff suspend')->group(function () {
            Route::post('staff/{staff}/suspend', [StaffController::class, 'suspend'])->name('staff.suspend');
            Route::post('staff/{staff}/activate', [StaffController::class, 'activate'])->name('staff.activate');
        });

        Route::middleware('permission:staff delete')->group(function () {
            Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        // Accounting
        Route::middleware('permission:accounting view')->prefix('accounting')->name('accounting.')->group(function () {
            Route::get('dashboard', [AccountingController::class, 'dashboard'])->name('dashboard');
            Route::get('accounts', [AccountingController::class, 'accounts'])->name('accounts.index');
            Route::get('journal', [AccountingController::class, 'journal'])->name('journal.index');
            Route::get('journal/{entry}', [AccountingController::class, 'journalShow'])->name('journal.show');
            Route::get('expenses', [AccountingController::class, 'expenses'])->name('expenses.index');
            Route::get('suppliers', [AccountingController::class, 'suppliers'])->name('suppliers.index');
            Route::get('bills', [AccountingController::class, 'bills'])->name('bills.index');
        });

        Route::middleware('permission:accounting reports')->prefix('accounting/reports')->name('accounting.reports.')->group(function () {
            Route::get('profit-and-loss', [AccountingController::class, 'profitAndLoss'])->name('profit-and-loss');
            Route::get('balance-sheet', [AccountingController::class, 'balanceSheet'])->name('balance-sheet');
            Route::get('trial-balance', [AccountingController::class, 'trialBalance'])->name('trial-balance');
            Route::get('general-ledger/{account}', [AccountingController::class, 'generalLedger'])->name('general-ledger');
            Route::get('ar-aging', [AccountingController::class, 'arAging'])->name('ar-aging');
            Route::get('ap-aging', [AccountingController::class, 'apAging'])->name('ap-aging');
            Route::get('vat-summary', [AccountingController::class, 'vatSummary'])->name('vat-summary');
            Route::get('expense-summary', [AccountingController::class, 'expenseSummary'])->name('expense-summary');
            Route::get('integrity', [AccountingController::class, 'integrity'])->name('integrity');
        });
    });
