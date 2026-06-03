<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\BusinessAuthController;
use App\Http\Controllers\Management\DashboardController;
use App\Http\Controllers\Management\KycController;
use App\Http\Controllers\Management\StoreController as VendorOnboardController;
use App\Http\Controllers\Management\ProductController;
use App\Http\Controllers\Management\CategoryController;
use App\Http\Controllers\Management\StoreController;
use App\Http\Controllers\Management\OrderController;
use App\Http\Controllers\Management\CustomerController;
use App\Http\Controllers\Management\TransactionController;
use App\Http\Controllers\Management\SubscriptionController;
use App\Http\Controllers\Management\ServiceController;
use App\Http\Controllers\Management\ProfileController;
use App\Http\Controllers\Management\StoreBankController;
use App\Http\Controllers\Management\StoreDeliveryRouteController;
use App\Http\Controllers\Management\PaymentSettingsController;
use App\Http\Controllers\Management\StaffController;
use App\Http\Controllers\Management\RoleController;
use App\Http\Controllers\Management\WarehouseController;
use App\Http\Controllers\Management\WarehouseTransferController;
use App\Http\Controllers\Management\SectionController;
use App\Http\Controllers\Management\StockTransferController;
use App\Http\Controllers\Management\SetupController;
use App\Http\Controllers\Management\PosSessionController;
use App\Http\Controllers\Management\PosController;
use App\Http\Controllers\Staff\InvitationController;

Route::prefix('management')->name('management.')->group(function () {
    // Public auth routes (redirect to old vendor auth for now)
    Route::get('/register', [BusinessAuthController::class, 'showRegister'])->name('auth.register');
    Route::post('/register', [BusinessAuthController::class, 'register'])->name('auth.register.store');
    Route::get('/login', [BusinessAuthController::class, 'showLogin'])->name('auth.login');
    Route::post('/login', [BusinessAuthController::class, 'login'])->name('auth.login.store');
    Route::get('/forgot-password', [BusinessAuthController::class, 'showForgotPassword'])->name('auth.forgot-password');
    Route::post('/forgot-password', [BusinessAuthController::class, 'sendResetOtp'])->name('auth.forgot-password.send');
    Route::get('/reset-password', [BusinessAuthController::class, 'showResetPassword'])->name('auth.reset-password');
    Route::post('/reset-password', [BusinessAuthController::class, 'resetPassword'])->name('auth.reset-password.update');
    Route::get('/verify-email', [BusinessAuthController::class, 'showVerifyOtp'])->name('auth.verify-otp');
    Route::post('/verify-email', [BusinessAuthController::class, 'verifyOtp'])->name('auth.verify-otp.store');
    Route::post('/verify-email/resend', [BusinessAuthController::class, 'resendOtp'])->name('auth.verify-otp.resend');

    Route::get('/staff/invitation/{token}', [InvitationController::class, 'showAccept'])->name('staff.invitation.accept');
    Route::post('/staff/invitation/{token}', [InvitationController::class, 'accept'])->name('staff.invitation.accept.store');

    Route::middleware(['auth', 'team.context'])->group(function () {
        Route::post('/logout', [BusinessAuthController::class, 'logout'])->name('auth.logout');
        Route::get('/logout', fn() => redirect()->route('management.auth.login'))->name('auth.logout.get');
        
        Route::get('/subscription', [SubscriptionController::class, 'showSubscriptionPlan'])->name('subscription.plan');
        Route::post('/subscription/initialize', [SubscriptionController::class, 'initializePayment'])->name('subscription.initialize');
        Route::get('/subscription/callback', [SubscriptionController::class, 'handleCallback'])->name('subscription.callback');
        Route::post('/subscription/check-early-pass', [SubscriptionController::class, 'checkEarlyPass'])->name('subscription.check-early-pass');
        Route::post('/subscription/activate-trial', [SubscriptionController::class, 'activateTrial'])->name('subscription.activate-trial');
        
        Route::get('/plans', [SubscriptionController::class, 'showPlans'])->name('plans.index');
        Route::get('/plans/checkout/{plan}', [SubscriptionController::class, 'showCheckout'])->name('plans.checkout');
        Route::post('/plans/validate-coupon', [SubscriptionController::class, 'validateCoupon'])->name('plans.validate-coupon');
        
        Route::get('/stores/create/onboarding', [StoreController::class, 'showStoreCreationForm'])->name('store.create');
        Route::post('/stores/create/onboarding', [StoreController::class, 'submitOnboardingStore'])->name('store.submit');
        Route::post('/stores/check-slug', [StoreController::class, 'checkSlugAvailability'])->name('store.check-slug');
        Route::get('/stores/create/success', [StoreController::class, 'success'])->name('stores.success');
        
        Route::get('/stores/set-delivery-routes', [StoreController::class, 'showDeliveryRoutesForm'])->name('delivery-routes.form');
        Route::post('/stores/set-delivery-routes', [StoreController::class, 'saveDeliveryRoutes'])->name('delivery-routes.save');
        
        Route::get('/stores/set-payment-methods', [StoreController::class, 'showPaymentMethods'])->name('payment-methods.form');
        Route::post('/stores/payment-methods/bank', [StoreController::class, 'storePaymentBank'])->name('payment-methods.bank');
        Route::post('/stores/payment-methods/paystack', [StoreController::class, 'storePaymentPaystack'])->name('payment-methods.paystack');
        Route::post('/stores/payment-methods/skip', [StoreController::class, 'skipPaymentMethods'])->name('payment-methods.skip');
        
        Route::get('/stores/get-banks', [StoreController::class, 'getBanks'])->name('store.get-banks');
        Route::post('/stores/validate-bank', [StoreController::class, 'validateBank'])->name('store.validate-bank');
        
        Route::middleware(['management.onboarding', 'management.subscription'])->group(function () {
            Route::get('/setup', [SetupController::class, 'show'])->name('setup');
            Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

            Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard view')->name('dashboard');
            Route::post('/switch-store', [DashboardController::class, 'switchStore'])->name('stores.switch');
            
            Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
            Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

            Route::get('/kyc', [KycController::class, 'show'])->name('kyc.show');
            Route::post('/kyc', [KycController::class, 'submit'])->name('kyc.submit');

            // Stores
            Route::middleware('permission:stores create')->group(function () {
                Route::get('/stores/create', [StoreController::class, 'create'])->name('stores.create');
                Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
            });
            Route::middleware('permission:stores view')->group(function () {
                Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
                Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
                Route::get('/stores/{store}/finalize', [StoreController::class, 'success'])->name('stores.success.new');
                Route::get('/stores/{store}/web-metrics', [StoreController::class, 'webMetrics'])->name('stores.web-metrics');
            });
            Route::middleware('permission:stores edit')->group(function () {
                Route::put('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
            });
            Route::middleware('permission:stores settings')->group(function () {
                Route::get('/stores/{store}/settings', [StoreController::class, 'settings'])->name('stores.settings');
                Route::patch('/stores/{store}/suspend', [StoreController::class, 'suspend'])->name('stores.suspend');
                Route::patch('/stores/{store}/activate', [StoreController::class, 'activate'])->name('stores.activate');
                Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');

                Route::post('/stores/{store}/assign-staff', [StoreController::class, 'assignStaff'])->name('stores.assign-staff');
                Route::delete('/stores/{store}/remove-staff/{user}', [StoreController::class, 'removeStaff'])->name('stores.remove-staff');

                Route::post('/stores/{store}/pos/enable', [StoreController::class, 'enablePos'])->name('pos.enable');
                Route::post('/stores/{store}/enable-website', [StoreController::class, 'enableWebsite'])->name('stores.enable-website');
            });

            Route::middleware('permission:stores settings')->group(function () {
                Route::post('/stores/{store}/banks', [StoreBankController::class, 'store'])->name('stores.banks.store');
                Route::put('/stores/{store}/banks/{bank}', [StoreBankController::class, 'update'])->name('stores.banks.update');
                Route::patch('/stores/{store}/banks/{bank}/primary', [StoreBankController::class, 'setPrimary'])->name('stores.banks.primary');
                Route::delete('/stores/{store}/banks/{bank}', [StoreBankController::class, 'destroy'])->name('stores.banks.destroy');

                Route::post('/stores/{store}/delivery-routes', [StoreDeliveryRouteController::class, 'store'])->name('stores.delivery-routes.store');
                Route::put('/stores/{store}/delivery-routes/{deliveryRoute}', [StoreDeliveryRouteController::class, 'update'])->name('stores.delivery-routes.update');
                Route::delete('/stores/{store}/delivery-routes/{deliveryRoute}', [StoreDeliveryRouteController::class, 'destroy'])->name('stores.delivery-routes.destroy');
            });

            // Payment Settings
            Route::middleware('permission:settings payment')->group(function () {
                Route::get('/payment-settings', [PaymentSettingsController::class, 'index'])->name('payment-settings.index');

                // Bank Accounts
                Route::post('/payment-settings/bank-accounts', [PaymentSettingsController::class, 'storeBankAccount'])->name('payment-settings.bank-accounts.store');
                Route::put('/payment-settings/bank-accounts/{bank}', [PaymentSettingsController::class, 'updateBankAccount'])->name('payment-settings.bank-accounts.update');
                Route::delete('/payment-settings/bank-accounts/{bank}', [PaymentSettingsController::class, 'destroyBankAccount'])->name('payment-settings.bank-accounts.destroy');
                Route::post('/payment-settings/verify-bank', [PaymentSettingsController::class, 'verifyBankAccount'])->name('payment-settings.verify-bank');

                // Store Payment Gateways
                Route::post('/payment-settings/gateways', [PaymentSettingsController::class, 'storePaystackKeys'])->name('payment-settings.gateways.store');
                Route::put('/payment-settings/gateways/{gateway}', [PaymentSettingsController::class, 'updatePaystackKeys'])->name('payment-settings.gateways.update');
                Route::delete('/payment-settings/gateways/{gateway}', [PaymentSettingsController::class, 'destroyPaystackKeys'])->name('payment-settings.gateways.destroy');
                Route::patch('/payment-settings/gateways/{gateway}/toggle', [PaymentSettingsController::class, 'togglePaystackKeys'])->name('payment-settings.gateways.toggle');
                Route::post('/payment-settings/gateways/{gateway}/test', [PaymentSettingsController::class, 'testGateway'])->name('payment-settings.gateways.test');

                // Payment Mode
                Route::post('/payment-settings/stores/{store}/toggle-mode', [PaymentSettingsController::class, 'togglePaymentMode'])->name('payment-settings.toggle-mode');
            });

            // Products
            Route::middleware('permission:products create')->group(function () {
                Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
                Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            });
            Route::middleware('permission:products view')->group(function () {
                Route::get('/products', [ProductController::class, 'index'])->name('products.index');
                Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
            });
            Route::middleware('permission:products edit')->group(function () {
                Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
                Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
                Route::put('/products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
            });
            Route::middleware('permission:products delete')->group(function () {
                Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
                Route::post('/products/bulk-delete', [ProductController::class, 'bulkDestroy'])->name('products.bulk-destroy');
            });

            // Services & Categories (products scope)
            Route::middleware('permission:products create')->group(function () {
                Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
                Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
                Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
                Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
            });
            Route::middleware('permission:products view')->group(function () {
                Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
                Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
                Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
            });
            Route::middleware('permission:products edit')->group(function () {
                Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
                Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
                Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            });
            Route::middleware('permission:products delete')->group(function () {
                Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
                Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
            });

            // Orders
            Route::middleware('permission:orders view')->group(function () {
                Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
                Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
                Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
            });
            Route::middleware('permission:orders edit')->group(function () {
                Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            });
            Route::middleware('permission:orders status_update')->group(function () {
                Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
                Route::patch('/orders/{order}/payment', [OrderController::class, 'updatePaymentStatus'])->name('orders.update-payment-status');
            });
            Route::middleware('permission:orders delete')->group(function () {
                Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
            });

            // Customers
            Route::middleware('permission:customers view')->group(function () {
                Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            });
            Route::middleware('permission:customers edit')->group(function () {
                Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
                Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            });
            Route::middleware('permission:customers suspend')->group(function () {
                Route::post('/customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
                Route::post('/customers/{customer}/activate', [CustomerController::class, 'activate'])->name('customers.activate');
            });

            // Transactions
            Route::middleware('permission:transactions view')->group(function () {
                Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
                Route::get('/transactions/{transaction:reference}', [TransactionController::class, 'show'])->name('transactions.show');
            });
            Route::middleware('permission:transactions confirm')->group(function () {
                Route::post('/transactions/{transaction:reference}/confirm', [TransactionController::class, 'confirmPayment'])->name('transactions.confirm');
            });
            Route::middleware('permission:transactions reject')->group(function () {
                Route::post('/transactions/{transaction:reference}/reject', [TransactionController::class, 'rejectPayment'])->name('transactions.reject');
            });
            Route::middleware('permission:transactions refund')->group(function () {
                Route::post('/transactions/{transaction:reference}/refund', [TransactionController::class, 'refundPayment'])->name('transactions.refund');
            });

            // Support
            Route::middleware('permission:support view_tickets')->group(function () {
                Route::get('/support-messages', [\App\Http\Controllers\Management\SupportMessageController::class, 'index'])->name('support-messages.index');
            });
            Route::middleware('permission:support reply')->group(function () {
                Route::post('/support-messages/{supportMessage}/reply', [\App\Http\Controllers\Management\SupportMessageController::class, 'reply'])->name('support-messages.reply');
            });

            // Staff
            Route::middleware('permission:staff create')->group(function () {
                Route::get('/staff/create', [StaffController::class, 'create'])->name('staff.create');
                Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
            });
            Route::middleware('permission:staff view')->group(function () {
                Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
                Route::get('/staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
            });
            Route::middleware('permission:staff edit')->group(function () {
                Route::get('/staff/{staff}/edit', [StaffController::class, 'edit'])->name('staff.edit');
                Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
                Route::post('/staff/{staff}/resend-invite', [StaffController::class, 'resendInvite'])->name('staff.resend-invite');
            });
            Route::middleware('permission:staff suspend')->group(function () {
                Route::patch('/staff/{staff}/suspend', [StaffController::class, 'suspend'])->name('staff.suspend');
                Route::patch('/staff/{staff}/activate', [StaffController::class, 'activate'])->name('staff.activate');
            });
            Route::middleware('permission:staff delete')->group(function () {
                Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
            });

            // Roles
            Route::middleware('permission:staff view')->group(function () {
                Route::resource('/roles', RoleController::class)->names([
                    'index' => 'roles.index', 'create' => 'roles.create', 'store' => 'roles.store',
                    'edit' => 'roles.edit', 'update' => 'roles.update', 'destroy' => 'roles.destroy',
                ]);
            });

            // Warehouses
            Route::middleware('permission:warehouses create')->group(function () {
                Route::get('/warehouses/create', [WarehouseController::class, 'create'])->name('warehouses.create');
                Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
            });
            Route::middleware('permission:warehouses view')->group(function () {
                Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
                Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show'])->name('warehouses.show');
            });
            Route::middleware('permission:warehouses edit')->group(function () {
                Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('warehouses.edit');
                Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
                Route::post('/warehouses/{warehouse}/move-products', [WarehouseController::class, 'moveProducts'])->name('warehouses.move-products');
            });
            Route::middleware('permission:warehouses delete')->group(function () {
                Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
            });

            Route::middleware('permission:warehouses view')->group(function () {
                Route::resource('/warehouses/{warehouse}/sections', SectionController::class)->names([
                    'index' => 'sections.index', 'create' => 'sections.create', 'store' => 'sections.store',
                    'show' => 'sections.show', 'edit' => 'sections.edit', 'update' => 'sections.update',
                    'destroy' => 'sections.destroy',
                ]);
            });

            // Stock Transfers
            Route::middleware('permission:transfers create')->group(function () {
                Route::get('/transfers/create', [StockTransferController::class, 'create'])->name('transfers.create');
                Route::post('/transfers', [StockTransferController::class, 'store'])->name('transfers.store');
                Route::patch('/transfers/{transfer}/submit', [StockTransferController::class, 'submit'])->name('transfers.submit');
                Route::patch('/transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
                Route::patch('/transfers/{transfer}/acknowledge', [StockTransferController::class, 'acknowledge'])->name('transfers.acknowledge');

                // Warehouse-specific transfer flows
                Route::get('/warehouses/{warehouse}/send', [WarehouseTransferController::class, 'sendForm'])->name('warehouses.send');
                Route::post('/warehouses/{warehouse}/send', [WarehouseTransferController::class, 'initSend'])->name('warehouses.send.store');
                Route::get('/warehouses/{warehouse}/receive', [WarehouseTransferController::class, 'receiveForm'])->name('warehouses.receive');
                Route::post('/warehouses/{warehouse}/receive', [WarehouseTransferController::class, 'initReceive'])->name('warehouses.receive.store');
                Route::get('/warehouses/{warehouse}/products-json', [WarehouseTransferController::class, 'productsJson'])->name('warehouses.products-json');
            });
            Route::middleware('permission:transfers view')->group(function () {
                Route::get('/transfers', [StockTransferController::class, 'index'])->name('transfers.index');
                Route::get('/transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
            });
            Route::middleware('permission:transfers approve')->group(function () {
                Route::patch('/transfers/{transfer}/approve', [StockTransferController::class, 'approve'])->name('transfers.approve');
                Route::patch('/transfers/{transfer}/reject', [StockTransferController::class, 'reject'])->name('transfers.reject');
            });
            Route::middleware('permission:transfers dispatch')->group(function () {
                Route::patch('/transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->name('transfers.dispatch');
            });
            Route::middleware('permission:transfers receive')->group(function () {
                Route::patch('/transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
            });

            // POS Sessions - per-store history
            Route::middleware('permission:pos view_history')->group(function () {
                Route::get('/pos/{store}/sessions', [PosSessionController::class, 'index'])->name('pos.sessions.index');
                Route::get('/pos/{store}/sessions/{session}', [PosSessionController::class, 'show'])->name('pos.sessions.show');
            });

            // POS Management
            Route::middleware('permission:pos view_history')->group(function () {
                Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
                Route::get('/pos/{store}/terminal', [PosController::class, 'terminal'])->name('pos.terminal');
                Route::get('/pos/{session}', [PosController::class, 'show'])->name('pos.show');
                Route::post('/pos/{store}/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
                Route::get('/pos/{store}/receipt/{order}', [PosController::class, 'receipt'])->name('pos.receipt');
            });
            Route::middleware('permission:pos open_session')->group(function () {
                Route::post('/stores/{store}/pos/open', [PosSessionController::class, 'open'])->name('pos.open');
            });
            Route::middleware('permission:pos close_session')->group(function () {
                Route::post('/stores/{store}/pos/close', [PosSessionController::class, 'close'])->name('pos.close');
            });
        });
    });
});
