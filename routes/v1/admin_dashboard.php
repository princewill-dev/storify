<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\VendorController;
use App\Http\Controllers\Admin\VendorKycApplicationController;
use App\Http\Controllers\Admin\LiveFirstController;
use App\Http\Controllers\Admin\StoreController;
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
use App\Http\Controllers\Admin\BulkbuyOrderController;
use App\Http\Controllers\Admin\FamilyPackOrderController;
use App\Http\Controllers\Admin\LiveFirstOrderController;
use App\Http\Controllers\Admin\VatController;
use App\Http\Controllers\Admin\DeliveryRouteController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\TransactionController;

// Admin Dashboard Routes (protected by auth middleware)
Route::middleware(['auth'])->group(function () {
    Route::get('/superadmin/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

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
        
        // Live First Program Management
        Route::get('live-first/applications', [LiveFirstController::class, 'index'])->name('live-first.index');
        Route::get('live-first/applications/{application}', [LiveFirstController::class, 'show'])->name('live-first.show');
        Route::post('live-first/applications/{application}/approve', [LiveFirstController::class, 'approve'])->name('live-first.approve');
        Route::post('live-first/applications/{application}/reject', [LiveFirstController::class, 'reject'])->name('live-first.reject');
        Route::post('live-first/applications/{application}/documents/{document}/toggle', [LiveFirstController::class, 'toggleDocumentVerification'])->name('live-first.document.toggle');
        
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

        // BULK BUY admin
        Route::get('bulkbuy/orders', [BulkbuyOrderController::class, 'index'])->name('bulkbuy.orders.index');

        // FAMILY PACK admin
        Route::get('family-pack/orders', [FamilyPackOrderController::class, 'index'])->name('familypack.orders.index');

        // LIVE FIRST admin
        Route::get('livefirst/orders', [LiveFirstOrderController::class, 'index'])->name('livefirst.orders.index');

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

        // Bulk Order Management
        Route::resource('bulk-orders', \App\Http\Controllers\Admin\AdminBulkOrderController::class)->only(['index', 'show', 'update']);
        Route::post('bulk-orders/{bulkOrder}/notify', [\App\Http\Controllers\Admin\AdminBulkOrderController::class, 'notify'])->name('bulk-orders.notify');
        Route::post('bulk-orders/{bulkOrder}/finalize', [\App\Http\Controllers\Admin\AdminBulkOrderController::class, 'finalize'])->name('bulk-orders.finalize');

        // Family Pack Management
        Route::get('family-packs', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'index'])->name('family-packs.index');
        // Show by pack code (e.g., PACK-XXXXXXXX) comes BEFORE numeric-id route
        Route::get('family-packs/{packCode}', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'showByCode'])
            ->where('packCode', 'PACK-[A-Za-z0-9]+')
            ->name('family-packs.show-by-code');
        // Fallback: show by numeric id
        Route::get('family-packs/{id}', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'show'])
            ->whereNumber('id')
            ->name('family-packs.show');
        Route::put('family-packs/{id}', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'update'])->name('family-packs.update');
        Route::post('family-packs/{id}/finalize', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'finalize'])->name('family-packs.finalize');
        Route::post('family-packs/{id}/activate', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'activate'])->name('family-packs.activate');
        Route::post('family-packs/{id}/subscription/pause', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'pauseSubscription'])->name('family-packs.subscription.pause');
        Route::post('family-packs/{id}/subscription/resume', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'resumeSubscription'])->name('family-packs.subscription.resume');
        Route::post('family-packs/{id}/subscription/cancel', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'cancelSubscription'])->name('family-packs.subscription.cancel');
        Route::post('family-packs/{id}/subscription/advance', [\App\Http\Controllers\Admin\AdminFamilyPackController::class, 'advanceNextCycle'])->name('family-packs.subscription.advance');

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
    });
});