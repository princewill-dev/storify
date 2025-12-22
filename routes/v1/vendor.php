<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Vendor\Auth\VendorAuthController;
use App\Http\Controllers\Vendor\VendorDashboardController;
use App\Http\Controllers\Vendor\VendorKycController;
use App\Http\Controllers\Vendor\VendorOnboardController;
use App\Http\Controllers\Vendor\VendorProductsController;
use App\Http\Controllers\Vendor\VendorCategoryController;
use App\Http\Controllers\Vendor\StoreController as VendorStoreController;
use App\Http\Controllers\Vendor\VendorOrderController;
use App\Http\Controllers\Vendor\VendorCustomerController;
use App\Http\Controllers\Vendor\VendorTransactionController;
use App\Http\Controllers\Vendor\VendorSubscriptionController;
use App\Http\Controllers\Vendor\VendorServicesController;

Route::prefix('vendor')->name('vendor.')->group(function () {
    Route::get('/register', [VendorAuthController::class, 'showRegister'])->name('auth.register');
    Route::post('/register', [VendorAuthController::class, 'register'])->name('auth.register.store');

    Route::get('/login', [VendorAuthController::class, 'showLogin'])->name('auth.login');
    Route::post('/login', [VendorAuthController::class, 'login'])->name('auth.login.store');

    Route::get('/forgot-password', [VendorAuthController::class, 'showForgotPassword'])->name('auth.forgot-password');
    Route::post('/forgot-password', [VendorAuthController::class, 'sendResetOtp'])->name('auth.forgot-password.send');

    Route::get('/reset-password', [VendorAuthController::class, 'showResetPassword'])->name('auth.reset-password');
    Route::post('/reset-password', [VendorAuthController::class, 'resetPassword'])->name('auth.reset-password.update');

    Route::get('/{vendor}/verify-email', [VendorAuthController::class, 'showVerifyOtp'])->name('auth.verify-otp');
    Route::post('/{vendor}/verify-email', [VendorAuthController::class, 'verifyOtp'])->name('auth.verify-otp.store');
    Route::post('/{vendor}/verify-email/resend', [VendorAuthController::class, 'resendOtp'])->name('auth.verify-otp.resend');

    Route::middleware('auth:vendor')->group(function () {
        Route::post('/logout', [VendorAuthController::class, 'logout'])->name('auth.logout');
        
        Route::get('/{vendor}/subscription/plan', [VendorSubscriptionController::class, 'showSubscriptionPlan'])->name('subscription.plan');
        Route::post('/{vendor}/subscription/initialize', [VendorSubscriptionController::class, 'initializePayment'])->name('subscription.initialize');
        Route::get('/{vendor}/subscription/callback', [VendorSubscriptionController::class, 'handleCallback'])->name('subscription.callback');
        Route::post('/{vendor}/subscription/check-early-pass', [VendorSubscriptionController::class, 'checkEarlyPass'])->name('subscription.check-early-pass');
        
        Route::get('/{vendor}/store/create', [VendorOnboardController::class, 'showStoreCreationForm'])->name('kyc.store.create');
        Route::post('/{vendor}/store/create', [VendorOnboardController::class, 'submitOnboardingStore'])->name('kyc.store.submit');
        Route::post('/{vendor}/store/check-slug', [VendorOnboardController::class, 'checkSlugAvailability'])->name('kyc.store.check-slug');
        Route::get('/{vendor}/store/success', [VendorOnboardController::class, 'showStoreSuccess'])->name('kyc.store.success');
        
        // Bank Validation Routes
        Route::get('/{vendor}/store/get-banks', [VendorOnboardController::class, 'getBanks'])->name('kyc.store.get-banks');
        Route::post('/{vendor}/store/validate-bank', [VendorOnboardController::class, 'validateBank'])->name('kyc.store.validate-bank');
        
        Route::middleware('vendor.subscription')->group(function () {
            Route::get('/dashboard', [VendorDashboardController::class, 'index'])->name('dashboard');
            
            // Profile
            Route::get('/dashboard/profile', [\App\Http\Controllers\Vendor\VendorProfileController::class, 'index'])->name('profile.index');
            Route::put('/dashboard/profile/password', [\App\Http\Controllers\Vendor\VendorProfileController::class, 'updatePassword'])->name('profile.password');

            Route::get('/{vendor}/kyc', [VendorKycController::class, 'show'])->name('kyc.show');
            Route::post('/{vendor}/kyc', [VendorKycController::class, 'submit'])->name('kyc.submit');
            Route::get('/{vendor}/stores/create', [VendorStoreController::class, 'create'])->name('stores.create');
            Route::post('/{vendor}/stores', [VendorStoreController::class, 'store'])->name('stores.store');
            Route::get('/stores', [VendorStoreController::class, 'index'])->name('stores.index');
            Route::get('/{vendor}/stores/{store}/finalize', [VendorStoreController::class, 'success'])->name('stores.success');
            Route::get('/{vendor}/stores/{store}', [VendorStoreController::class, 'show'])->name('stores.show');
            Route::put('/stores/{store}', [VendorStoreController::class, 'update'])->name('stores.update');
            Route::get('/{vendor}/products', [VendorProductsController::class, 'index'])->name('products.index');
            Route::get('/{vendor}/products/create', [VendorProductsController::class, 'create'])->name('products.create');
            Route::post('/{vendor}/products', [VendorProductsController::class, 'store'])->name('products.store');
            Route::get('/{vendor}/products/{product}', [VendorProductsController::class, 'show'])->name('products.show');
            Route::get('/{vendor}/products/{product}/edit', [VendorProductsController::class, 'edit'])->name('products.edit');
            Route::put('/{vendor}/products/{product}', [VendorProductsController::class, 'update'])->name('products.update');
            Route::put('/{vendor}/products/{product}/status', [VendorProductsController::class, 'updateStatus'])->name('products.status');
            Route::delete('/{vendor}/products/{product}', [VendorProductsController::class, 'destroy'])->name('products.destroy');

            Route::get('/{vendor}/services', [VendorServicesController::class, 'index'])->name('services.index');
            Route::get('/{vendor}/services/create', [VendorServicesController::class, 'create'])->name('services.create');
            Route::post('/{vendor}/services', [VendorServicesController::class, 'store'])->name('services.store');
            Route::get('/{vendor}/services/{service}/edit', [VendorServicesController::class, 'edit'])->name('services.edit');
            Route::put('/{vendor}/services/{service}', [VendorServicesController::class, 'update'])->name('services.update');
            Route::delete('/{vendor}/services/{service}', [VendorServicesController::class, 'destroy'])->name('services.destroy');
            Route::get('/{vendor}/categories', [VendorCategoryController::class, 'index'])->name('categories.index');
            Route::get('/{vendor}/categories/create', [VendorCategoryController::class, 'create'])->name('categories.create');
            Route::post('/{vendor}/categories', [VendorCategoryController::class, 'store'])->name('categories.store');
            Route::get('/{vendor}/categories/{category}/edit', [VendorCategoryController::class, 'edit'])->name('categories.edit');
            Route::put('/{vendor}/categories/{category}', [VendorCategoryController::class, 'update'])->name('categories.update');
            Route::delete('/{vendor}/categories/{category}', [VendorCategoryController::class, 'destroy'])->name('categories.destroy');
            Route::get('/{vendor}/orders', [VendorOrderController::class, 'index'])->name('orders.index');
            Route::get('/{vendor}/orders/{order}', [VendorOrderController::class, 'show'])->name('orders.show');
            Route::get('/{vendor}/orders/{order}/edit', [VendorOrderController::class, 'edit'])->name('orders.edit');
            Route::put('/{vendor}/orders/{order}', [VendorOrderController::class, 'update'])->name('orders.update');
            Route::patch('/{vendor}/orders/{order}/status', [VendorOrderController::class, 'updateStatus'])->name('orders.update-status');
            Route::patch('/{vendor}/orders/{order}/payment', [VendorOrderController::class, 'updatePaymentStatus'])->name('orders.update-payment-status');
            Route::delete('/{vendor}/orders/{order}', [VendorOrderController::class, 'destroy'])->name('orders.destroy');
            Route::get('/{vendor}/customers', [VendorCustomerController::class, 'index'])->name('customers.index');
            Route::get('/{vendor}/customers/{customer}', [VendorCustomerController::class, 'show'])->name('customers.show');
            Route::get('/{vendor}/customers/{customer}/edit', [VendorCustomerController::class, 'edit'])->name('customers.edit');
            Route::put('/{vendor}/customers/{customer}', [VendorCustomerController::class, 'update'])->name('customers.update');
            Route::post('/{vendor}/customers/{customer}/suspend', [VendorCustomerController::class, 'suspend'])->name('customers.suspend');
            Route::post('/{vendor}/customers/{customer}/activate', [VendorCustomerController::class, 'activate'])->name('customers.activate');
            Route::get('/{vendor}/transactions', [VendorTransactionController::class, 'index'])->name('transactions.index');
            Route::get('/{vendor}/transactions/{transaction:reference}', [VendorTransactionController::class, 'show'])->name('transactions.show');
            
            // Support Messages
            Route::get('/{vendor}/support-messages', [\App\Http\Controllers\Vendor\SupportMessageController::class, 'index'])->name('support-messages.index');
            Route::post('/{vendor}/support-messages/{supportMessage}/reply', [\App\Http\Controllers\Vendor\SupportMessageController::class, 'reply'])->name('support-messages.reply');
        });
    });

    Route::redirect('/', '/vendor/dashboard');
});
