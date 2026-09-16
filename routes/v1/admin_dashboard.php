<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminEarlyPassController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminsController;
use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessKycApplicationController;
use App\Http\Controllers\Admin\BusinessTypeController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CompanyServiceController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DeliveryIntervalController;
use App\Http\Controllers\Admin\DeliveryRouteController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OwnershipTypeController;
use App\Http\Controllers\Admin\PageStylingController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\Shop4meOrderController;
use App\Http\Controllers\Admin\StockTransferController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\StorefrontSlideController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\SupportMessageController;
use App\Http\Controllers\Admin\TestimonialController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VatController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Middleware\AdminRouteActivityLogger;
use App\Models\KycApplication;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Admin Dashboard Routes (protected by auth + admin role middleware)
Route::middleware(['auth', 'platform.admin', 'team.context'])->group(function () {
    Route::get('/office/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/office/executive', fn () => redirect()->route('admin.dashboard'))->name('admin.executive');

    Route::get('/office/settings', [AdminSettingsController::class, 'edit'])->name('admin.settings.edit')->middleware('permission:admin.settings');
    Route::post('/office/settings', [AdminSettingsController::class, 'update'])->name('admin.settings.update')->middleware('permission:admin.settings');

    Route::prefix('office')->name('admin.')->middleware([AdminRouteActivityLogger::class])->group(function () {

        Route::middleware('permission:admin.activity-logs')->group(function () {
            Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        });

        // Admin (platform staff) management — superadmin only
        Route::middleware('permission:admin.admins')->group(function () {
            Route::get('admins', [AdminsController::class, 'index'])->name('admins.index');
            Route::post('admins', [AdminsController::class, 'store'])->name('admins.store');
            Route::post('admins/{admin}/resend', [AdminsController::class, 'resend'])->name('admins.resend');
            Route::put('admins/{admin}', [AdminsController::class, 'update'])->name('admins.update');
            Route::delete('admins/{admin}', [AdminsController::class, 'destroy'])->name('admins.destroy');
        });

        // Users (business owners + staff)
        Route::middleware('permission:admin.users')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
            Route::post('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
            Route::post('users/{user}/verify', [UserController::class, 'verify'])->name('users.verify');
            Route::post('users/{user}/unverify', [UserController::class, 'unverify'])->name('users.unverify');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

        Route::middleware('permission:admin.users.impersonate')->group(function () {
            Route::post('users/{user}/impersonate', [UserController::class, 'impersonate'])->name('users.impersonate');
        });

        // Platform books (accounting)
        Route::middleware('permission:admin.accounting')->group(function () {            Route::get('accounting', [AccountingController::class, 'index'])->name('accounting.index');
            Route::get('accounting/accounts', [AccountingController::class, 'accounts'])->name('accounting.accounts');
            Route::get('accounting/journal', [AccountingController::class, 'journal'])->name('accounting.journal');
            Route::get('accounting/journal/{entry}', [AccountingController::class, 'journalShow'])->name('accounting.journal.show');
            Route::get('accounting/reports', [AccountingController::class, 'reports'])->name('accounting.reports');
            Route::get('accounting/settings', [AccountingController::class, 'settings'])->name('accounting.settings');
            Route::put('accounting/settings/mappings', [AccountingController::class, 'updateMappings'])->name('accounting.settings.mappings.update');
            Route::post('accounting/settings/periods/{period}/close', [AccountingController::class, 'closePeriod'])->name('accounting.settings.periods.close');
            Route::post('accounting/settings/periods/{period}/reopen', [AccountingController::class, 'reopenPeriod'])->name('accounting.settings.periods.reopen');
            Route::post('accounting/settings/years/{year}/close', [AccountingController::class, 'closeYear'])->name('accounting.settings.years.close');
        });

        // Businesses + KYC
        Route::middleware('permission:admin.businesses')->group(function () {
            Route::resource('businesses', BusinessController::class)
                ->parameters(['businesses' => 'user'])
                ->except(['show', 'create', 'edit']);
            Route::get('businesses/{user}', [BusinessController::class, 'show'])->name('businesses.show');
            Route::post('businesses/{user}/suspend', [BusinessController::class, 'suspend'])->name('businesses.suspend');
            Route::post('businesses/{user}/activate', [BusinessController::class, 'activate'])->name('businesses.activate');
            Route::get('business-kyc-applications', [BusinessKycApplicationController::class, 'index'])->name('business-kyc.index');
            Route::get('business-kyc-applications/{application}', [BusinessKycApplicationController::class, 'show'])->name('business-kyc.show');
            Route::post('business-kyc-applications/{application}/approve', [BusinessKycApplicationController::class, 'approve'])->name('business-kyc.approve');
            Route::post('business-kyc-applications/{application}/reject', [BusinessKycApplicationController::class, 'reject'])->name('business-kyc.reject');
            Route::resource('early-access', AdminEarlyPassController::class)
                ->parameters(['early-access' => 'earlyPass'])
                ->except(['create', 'edit']);
            Route::post('early-access/{earlyPass}/toggle-status', [AdminEarlyPassController::class, 'toggleStatus'])->name('early-access.toggle-status');

            // Compatibility boundary for existing admin bookmarks and queued links.
            Route::get('vendors', fn () => redirect()->route('admin.businesses.index', status: 301))->name('legacy.vendors.index');
            Route::get('vendors/{user}', fn (User $user) => redirect()->route('admin.businesses.show', $user, 301))->name('legacy.vendors.show');
            Route::get('vendor-kyc-applications', fn () => redirect()->route('admin.business-kyc.index', status: 301))->name('legacy.vendor-kyc.index');
            Route::get('vendor-kyc-applications/{application}', fn (KycApplication $application) => redirect()->route('admin.business-kyc.show', $application, 301))->name('legacy.vendor-kyc.show');
        });

        // Stores
        Route::middleware('permission:admin.stores')->group(function () {
            Route::resource('stores', StoreController::class)->except(['show', 'create', 'edit']);
            Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
            Route::post('stores/{store}/suspend', [StoreController::class, 'suspend'])->name('stores.suspend');
            Route::post('stores/{store}/activate', [StoreController::class, 'activate'])->name('stores.activate');
        });

        // Products + Categories
        Route::middleware('permission:admin.products')->group(function () {
            Route::resource('products', ProductController::class)->except(['show']);
            Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
            Route::put('products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
            Route::get('stores/{store}/products', [ProductController::class, 'index'])->name('stores.products.index');
            Route::get('stores/{store}/product/create', [ProductController::class, 'create'])->name('stores.product.create');
            Route::get('stores/{store}/products/{code}', [ProductController::class, 'showInStore'])->name('stores.products.show');

            Route::resource('categories', CategoryController::class)->except(['show']);
            Route::get('stores/{store}/categories', [CategoryController::class, 'index'])->name('stores.categories.index');
            Route::get('stores/{store}/categories/create', [CategoryController::class, 'create'])->name('stores.categories.create');
        });

        // Warehouses + Transfers
        Route::middleware('permission:admin.warehouses')->group(function () {
            Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
            Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show');

            Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
            Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
            Route::patch('transfers/{transfer}/approve', [StockTransferController::class, 'approve'])->name('transfers.approve');
            Route::patch('transfers/{transfer}/reject', [StockTransferController::class, 'reject'])->name('transfers.reject');
            Route::patch('transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');
            Route::patch('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
        });

        // Content (styling, business/ownership types, services, slides, testimonials, features)
        Route::middleware('permission:admin.content')->group(function () {
            Route::resource('styling', PageStylingController::class)->except(['show']);
            Route::resource('business-types', BusinessTypeController::class)->parameters(['business-types' => 'businessType'])->except(['show']);
            Route::resource('ownership-types', OwnershipTypeController::class)->parameters(['ownership-types' => 'ownershipType'])->except(['show']);
            Route::resource('company-services', CompanyServiceController::class)->except(['create', 'edit', 'show']);
            Route::post('company-services/{companyService}/toggle', [CompanyServiceController::class, 'toggle'])->name('company-services.toggle');
            Route::post('company-services/reorder', [CompanyServiceController::class, 'reorder'])->name('company-services.reorder');

            Route::get('stores/{store}/storefront-slides', [StorefrontSlideController::class, 'index'])->name('storefront-slides.index');
            Route::post('stores/{store}/storefront-slides', [StorefrontSlideController::class, 'store'])->name('storefront-slides.store');
            Route::put('stores/{store}/storefront-slides/{slide}', [StorefrontSlideController::class, 'update'])->name('storefront-slides.update');
            Route::delete('stores/{store}/storefront-slides/{slide}', [StorefrontSlideController::class, 'destroy'])->name('storefront-slides.destroy');

            Route::resource('features', FeatureController::class)->except(['create', 'show', 'edit']);
            Route::post('features/reorder', [FeatureController::class, 'reorder'])->name('features.reorder');

            Route::resource('testimonials', TestimonialController::class)->except(['create', 'show', 'edit']);
        });

        // Finance (payment methods, bank accounts, VAT)
        Route::middleware('permission:admin.finance')->group(function () {
            Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
            Route::post('payment-methods/{paymentMethod}/toggle', [PaymentMethodController::class, 'toggle'])->name('payment-methods.toggle');

            Route::resource('bank-accounts', BankAccountController::class);
            Route::post('bank-accounts/{bankAccount}/toggle-active', [BankAccountController::class, 'toggleActive'])->name('bank-accounts.toggle-active');

            Route::resource('vats', VatController::class)->except(['show', 'create']);
            Route::post('vats/{vat}/toggle', [VatController::class, 'toggle'])->name('vats.toggle');
        });

        // Delivery
        Route::middleware('permission:admin.delivery')->group(function () {
            Route::resource('delivery-routes', DeliveryRouteController::class)->except(['show', 'create']);
            Route::post('delivery-routes/{deliveryRoute}/toggle', [DeliveryRouteController::class, 'toggle'])->name('delivery-routes.toggle');
            Route::resource('delivery-intervals', DeliveryIntervalController::class)->except(['create', 'edit', 'show']);
            Route::post('delivery-intervals/{id}/toggle', [DeliveryIntervalController::class, 'toggle'])->name('delivery-intervals.toggle');
        });

        // Orders
        Route::middleware('permission:admin.orders')->group(function () {
            Route::get('shop4me/orders', [Shop4meOrderController::class, 'index'])->name('shop4me.orders.index');
            Route::resource('orders', OrderController::class)->except(['create', 'store']);
            Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
            Route::patch('orders/{order}/payment-status', [OrderController::class, 'updatePaymentStatus'])->name('orders.update-payment-status');
        });

        // Transactions
        Route::middleware('permission:admin.transactions')->group(function () {
            Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
            Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
            Route::patch('transactions/{transaction}/status', [TransactionController::class, 'updateStatus'])->name('transactions.update-status');
        });

        // Customers
        Route::middleware('permission:admin.customers')->group(function () {
            Route::resource('customers', CustomerController::class)->only(['index', 'show', 'edit', 'update']);
            Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
            Route::post('customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');
        });

        // Support
        Route::middleware('permission:admin.support')->group(function () {
            Route::get('support-messages', [SupportMessageController::class, 'index'])->name('support-messages.index');
            Route::post('support-messages/{supportMessage}/reply', [SupportMessageController::class, 'reply'])->name('support-messages.reply');
            Route::delete('support-messages/{supportMessage}', [SupportMessageController::class, 'destroy'])->name('support-messages.destroy');
        });

        // Subscriptions + Plans + Coupons
        Route::middleware('permission:admin.subscriptions')->group(function () {
            Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
            Route::resource('subscription-plans', SubscriptionPlanController::class)->only(['index', 'store', 'update', 'destroy']);
        });

        Route::middleware('permission:admin.coupons')->group(function () {
            Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
            Route::get('coupons/create', [CouponController::class, 'create'])->name('coupons.create');
            Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
            Route::get('coupons/{coupon}/edit', [CouponController::class, 'edit'])->name('coupons.edit');
            Route::put('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
            Route::post('coupons/{coupon}/toggle', [CouponController::class, 'toggleActive'])->name('coupons.toggle');
            Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');
        });
    });
});
