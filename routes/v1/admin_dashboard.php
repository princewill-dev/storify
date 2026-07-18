<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\VendorController;
use App\Http\Controllers\Admin\VendorKycApplicationController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\Admin\StockTransferController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\BusinessTypeController;
use App\Http\Controllers\Admin\OwnershipTypeController;
use App\Http\Controllers\Admin\CompanyServiceController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Middleware\AdminRouteActivityLogger;
use App\Http\Controllers\Admin\StorefrontSlideController;
use App\Http\Controllers\Admin\Shop4meOrderController;
use App\Http\Controllers\Admin\VatController;
use App\Http\Controllers\Admin\DeliveryRouteController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\SubscriptionPlanController;

// Admin Dashboard Routes (protected by auth middleware)
Route::middleware(['auth'])->group(function () {
    Route::get('/superadmin/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/superadmin/executive', fn() => redirect()->route('admin.dashboard'))->name('admin.executive');

    Route::get('/superadmin/settings', [AdminSettingsController::class, 'edit'])->name('admin.settings.edit');
    Route::post('/superadmin/settings', [AdminSettingsController::class, 'update'])->name('admin.settings.update');

    Route::prefix('superadmin')->name('admin.')->middleware([AdminRouteActivityLogger::class])->group(function() {
        Route::resource('styling', \App\Http\Controllers\Admin\PageStylingController::class);
        
        Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::resource('vendors', VendorController::class)->except(['show']);
        Route::get('vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
        Route::post('vendors/{vendor}/suspend', [VendorController::class, 'suspend'])->name('vendors.suspend');
        Route::post('vendors/{vendor}/activate', [VendorController::class, 'activate'])->name('vendors.activate');
        Route::get('vendor-kyc-applications', [VendorKycApplicationController::class, 'index'])->name('vendor-kyc.index');
        Route::get('vendor-kyc-applications/{application}', [VendorKycApplicationController::class, 'show'])->name('vendor-kyc.show');
        Route::post('vendor-kyc-applications/{application}/approve', [VendorKycApplicationController::class, 'approve'])->name('vendor-kyc.approve');
        Route::post('vendor-kyc-applications/{application}/reject', [VendorKycApplicationController::class, 'reject'])->name('vendor-kyc.reject');
        Route::resource('stores', StoreController::class)->except(['show']);
        Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
        Route::post('stores/{store}/suspend', [StoreController::class, 'suspend'])->name('stores.suspend');
        Route::post('stores/{store}/activate', [StoreController::class, 'activate'])->name('stores.activate');
        Route::resource('products', ProductController::class)->except(['show']);
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        // Status-only update for products (avoid full ProductRequest)
        Route::put('products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
        // Pretty product URLs scoped to a store
        Route::get('stores/{store}/products', [ProductController::class, 'index'])->name('stores.products.index');
        Route::get('stores/{store}/product/create', [ProductController::class, 'create'])->name('stores.product.create');
        Route::get('stores/{store}/products/{code}', [ProductController::class, 'showInStore'])->name('stores.products.show');

        Route::resource('categories', CategoryController::class)->except(['show']);
        // Pretty category URLs scoped to a store
        Route::get('stores/{store}/categories', [CategoryController::class, 'index'])->name('stores.categories.index');
        Route::get('stores/{store}/categories/create', [CategoryController::class, 'create'])->name('stores.categories.create');

        // Warehouses (admin overview)
        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show');

        // Stock Transfers (admin overview)
        Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
        Route::patch('transfers/{transfer}/approve', [StockTransferController::class, 'approve'])->name('transfers.approve');
        Route::patch('transfers/{transfer}/reject', [StockTransferController::class, 'reject'])->name('transfers.reject');
        Route::patch('transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');
        Route::patch('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');

        Route::resource('business-types', BusinessTypeController::class)->parameters(['business-types' => 'businessType'])->except(['show']);
        Route::resource('ownership-types', OwnershipTypeController::class)->parameters(['ownership-types' => 'ownershipType'])->except(['show']);
        // Company Services
        Route::resource('company-services', CompanyServiceController::class)->except(['create','edit','show']);
        Route::post('company-services/{companyService}/toggle', [CompanyServiceController::class, 'toggle'])->name('company-services.toggle');
        Route::post('company-services/reorder', [CompanyServiceController::class, 'reorder'])->name('company-services.reorder');
        
        Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::post('payment-methods/{paymentMethod}/toggle', [PaymentMethodController::class, 'toggle'])->name('payment-methods.toggle');
        
        // Bank Accounts
        Route::resource('bank-accounts', BankAccountController::class);
        Route::post('bank-accounts/{bankAccount}/toggle-active', [BankAccountController::class, 'toggleActive'])->name('bank-accounts.toggle-active');
        
        // Storefront slides
        Route::get('stores/{store}/storefront-slides', [StorefrontSlideController::class, 'index'])->name('storefront-slides.index');
        Route::post('stores/{store}/storefront-slides', [StorefrontSlideController::class, 'store'])->name('storefront-slides.store');
        Route::put('stores/{store}/storefront-slides/{slide}', [StorefrontSlideController::class, 'update'])->name('storefront-slides.update');
        Route::delete('stores/{store}/storefront-slides/{slide}', [StorefrontSlideController::class, 'destroy'])->name('storefront-slides.destroy');

        // SHOP4ME admin
        Route::get('shop4me/orders', [Shop4meOrderController::class, 'index'])->name('shop4me.orders.index');

        // VAT
        Route::resource('vats', VatController::class)->except(['show']);
        Route::post('vats/{vat}/toggle', [VatController::class, 'toggle'])->name('vats.toggle');

        // Delivery Routes
        Route::resource('delivery-routes', DeliveryRouteController::class)->except(['show']);
        Route::post('delivery-routes/{deliveryRoute}/toggle', [DeliveryRouteController::class, 'toggle'])->name('delivery-routes.toggle');

        // Order Management
        Route::resource('orders', OrderController::class)->except(['create', 'store']);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
        Route::patch('orders/{order}/payment-status', [OrderController::class, 'updatePaymentStatus'])->name('orders.update-payment-status');

        // Delivery Intervals
        Route::resource('delivery-intervals', \App\Http\Controllers\Admin\DeliveryIntervalController::class)->except(['create', 'edit', 'show']);
        Route::post('delivery-intervals/{id}/toggle', [\App\Http\Controllers\Admin\DeliveryIntervalController::class, 'toggle'])->name('delivery-intervals.toggle');

        // Transaction Management
        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::patch('transactions/{transaction}/status', [TransactionController::class, 'updateStatus'])->name('transactions.update-status');

        // Feature CTAs
        Route::resource('features', FeatureController::class)->except(['create', 'show', 'edit']);
        Route::post('features/reorder', [FeatureController::class, 'reorder'])->name('features.reorder');

        // Customer Management
        Route::resource('customers', CustomerController::class)->only(['index', 'show', 'edit', 'update']);
        Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
        Route::post('customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');

        // Testimonials
        Route::resource('testimonials', \App\Http\Controllers\Admin\TestimonialController::class)->except(['create', 'show', 'edit']);

        // Support Messages
        Route::get('support-messages', [\App\Http\Controllers\Admin\SupportMessageController::class, 'index'])->name('support-messages.index');
        Route::post('support-messages/{supportMessage}/reply', [\App\Http\Controllers\Admin\SupportMessageController::class, 'reply'])->name('support-messages.reply');
        Route::delete('support-messages/{supportMessage}', [\App\Http\Controllers\Admin\SupportMessageController::class, 'destroy'])->name('support-messages.destroy');

        // Early Access Codes
        Route::resource('early-access', \App\Http\Controllers\Admin\AdminEarlyPassController::class)
            ->parameters(['early-access' => 'earlyPass'])
            ->except(['create', 'edit']);
        Route::post('early-access/{earlyPass}/toggle-status', [\App\Http\Controllers\Admin\AdminEarlyPassController::class, 'toggleStatus'])->name('early-access.toggle-status');

        // Subscriptions
        Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');

        // Subscription Plans
        Route::resource('subscription-plans', SubscriptionPlanController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});