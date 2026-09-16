<?php

use App\Http\Controllers\Auth\BusinessAuthController;
use App\Http\Controllers\Management\Accounting\AccountingDashboardController;
use App\Http\Controllers\Management\Accounting\AccountingReportController;
use App\Http\Controllers\Management\Accounting\AccountingSettingsController;
use App\Http\Controllers\Management\Accounting\BankReconciliationController;
use App\Http\Controllers\Management\Accounting\BillController;
use App\Http\Controllers\Management\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Management\Accounting\ExpenseController;
use App\Http\Controllers\Management\Accounting\JournalController;
use App\Http\Controllers\Management\Accounting\SupplierController;
use App\Http\Controllers\Management\CategoryController;
use App\Http\Controllers\Management\CustomerController;
use App\Http\Controllers\Management\DashboardController;
use App\Http\Controllers\Management\DispatchesController;
use App\Http\Controllers\Management\EarlyPassController;
use App\Http\Controllers\Management\InvoiceController;
use App\Http\Controllers\Management\KycController;
use App\Http\Controllers\Management\OrderController;
use App\Http\Controllers\Management\PaymentSettingsController;
use App\Http\Controllers\Management\PosController;
use App\Http\Controllers\Management\PosSessionController;
use App\Http\Controllers\Management\ProductController;
use App\Http\Controllers\Management\ProfileController;
use App\Http\Controllers\Management\RoleController;
use App\Http\Controllers\Management\SearchController;
use App\Http\Controllers\Management\SectionController;
use App\Http\Controllers\Management\ServiceController;
use App\Http\Controllers\Management\SetupController;
use App\Http\Controllers\Management\StaffController;
use App\Http\Controllers\Management\StockTransferController;
use App\Http\Controllers\Management\StoreBankController;
use App\Http\Controllers\Management\StoreController;
use App\Http\Controllers\Management\StoreDashboardController;
use App\Http\Controllers\Management\StoreDeliveryRouteController;
use App\Http\Controllers\Management\StorefrontController;
use App\Http\Controllers\Management\StoreLifecycleController;
use App\Http\Controllers\Management\StoreSettingsController;
use App\Http\Controllers\Management\StoreTabController;
use App\Http\Controllers\Management\SubscriptionCouponController;
use App\Http\Controllers\Management\SubscriptionPaymentController;
use App\Http\Controllers\Management\SubscriptionPlanController;
use App\Http\Controllers\Management\SupportMessageController;
use App\Http\Controllers\Management\TransactionController;
use App\Http\Controllers\Management\WarehouseController;
use App\Http\Controllers\Staff\InvitationController;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Route;

Route::prefix('management')->name('management.')->group(function () {
    // Public business authentication routes.
    Route::get('/register', [BusinessAuthController::class, 'showRegister'])->name('auth.register');
    Route::post('/register', [BusinessAuthController::class, 'register'])->name('auth.register.store')->middleware('throttle:6,1');
    Route::get('/login', [BusinessAuthController::class, 'showLogin'])->name('auth.login');
    Route::post('/login', [BusinessAuthController::class, 'login'])->name('auth.login.store')->middleware('throttle:6,1');
    Route::get('/forgot-password', [BusinessAuthController::class, 'showForgotPassword'])->name('auth.forgot-password');
    Route::post('/forgot-password', [BusinessAuthController::class, 'sendResetOtp'])->name('auth.forgot-password.send')->middleware('throttle:3,10');
    Route::get('/reset-password', [BusinessAuthController::class, 'showResetPassword'])->name('auth.reset-password');
    Route::post('/reset-password', [BusinessAuthController::class, 'resetPassword'])->name('auth.reset-password.update');
    Route::get('/verify-email', [BusinessAuthController::class, 'showVerifyOtp'])->name('auth.verify-otp');
    Route::post('/verify-email', [BusinessAuthController::class, 'verifyOtp'])->name('auth.verify-otp.store')->middleware('throttle:6,1');
    Route::post('/verify-email/resend', [BusinessAuthController::class, 'resendOtp'])->name('auth.verify-otp.resend')->middleware('throttle:3,10');

    Route::get('/staff/invitation/{token}', [InvitationController::class, 'showAccept'])->name('staff.invitation.accept');
    Route::post('/staff/invitation/{token}', [InvitationController::class, 'accept'])->name('staff.invitation.accept.store');

    Route::middleware(['auth', 'team.context'])->group(function () {
        Route::post('/logout', [BusinessAuthController::class, 'logout'])->name('auth.logout');
        Route::get('/logout', [BusinessAuthController::class, 'logout'])->name('auth.logout.get');

        Route::get('/subscription', [SubscriptionPlanController::class, 'index'])->name('subscription.plan');
        Route::post('/subscription/select-plan', [SubscriptionPlanController::class, 'select'])->name('subscription.select-plan');
        Route::get('/subscription/payment', [SubscriptionPaymentController::class, 'show'])->name('subscription.payment');
        Route::post('/subscription/process-payment', [SubscriptionPaymentController::class, 'initialize'])->name('subscription.process-payment');
        Route::post('/subscription/change-plan', [SubscriptionPlanController::class, 'change'])->name('subscription.change-plan');
        Route::get('/subscription/callback', [SubscriptionPaymentController::class, 'callback'])->name('subscription.callback');
        Route::post('/subscription/check-early-pass', [EarlyPassController::class, 'apply'])->name('subscription.check-early-pass');

        Route::get('/plans', [SubscriptionPlanController::class, 'onboarding'])->name('plans.index');
        Route::get('/plans/checkout/{plan}', [SubscriptionPlanController::class, 'checkout'])->name('plans.checkout');
        Route::post('/plans/validate-coupon', [SubscriptionCouponController::class, 'validateCoupon'])->name('plans.validate-coupon');
        Route::post('/plans/remove-coupon', [SubscriptionCouponController::class, 'remove'])->name('plans.remove-coupon');

        Route::post('/stores/check-slug', [StoreController::class, 'checkSlugAvailability'])->name('store.check-slug');

        Route::middleware(['management.onboarding', 'management.subscription'])->group(function () {
            Route::get('/setup', [SetupController::class, 'show'])->name('setup');
            Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

            Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard view')->name('dashboard');
            Route::post('/switch-store', [DashboardController::class, 'switchStore'])->name('stores.switch');
            Route::get('/search', SearchController::class)->name('search');

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
                Route::get('/stores/{store}', [StoreDashboardController::class, 'show'])->name('stores.show');
                Route::get('/stores/{store}/finalize', [StoreController::class, 'success'])->name('stores.success.new');
                Route::get('/stores/{store}/web-metrics', [StoreDashboardController::class, 'webMetrics'])->name('stores.web-metrics');
                // AJAX tab endpoints for store detail page
                Route::get('/stores/{store}/tab/{tab}', [StoreTabController::class, 'show'])->name('stores.tab');
            });
            Route::middleware('permission:stores edit')->group(function () {
                Route::put('/stores/{store}', [StoreSettingsController::class, 'update'])->name('stores.update');
            });
            Route::middleware('permission:stores settings')->group(function () {
                Route::get('/stores/{store}/settings', [StoreSettingsController::class, 'show'])->name('stores.settings');
                Route::patch('/stores/{store}/suspend', [StoreLifecycleController::class, 'suspend'])->name('stores.suspend');
                Route::patch('/stores/{store}/activate', [StoreLifecycleController::class, 'activate'])->name('stores.activate');
                Route::delete('/stores/{store}', [StoreLifecycleController::class, 'destroy'])->name('stores.destroy');

                Route::post('/stores/{store}/assign-staff', [StoreSettingsController::class, 'assignStaff'])->name('stores.assign-staff');
                Route::delete('/stores/{store}/remove-staff/{user}', [StoreSettingsController::class, 'removeStaff'])->name('stores.remove-staff');

                Route::post('/stores/{store}/assign-bank', [StoreSettingsController::class, 'assignBank'])->name('stores.assign-bank');
                Route::delete('/stores/{store}/remove-bank/{bank}', [StoreSettingsController::class, 'removeBank'])->name('stores.remove-bank');

                Route::post('/stores/{store}/pos/enable', [StoreSettingsController::class, 'enablePos'])->name('pos.enable');
                Route::post('/stores/{store}/enable-website', [StorefrontController::class, 'enableWebsite'])->name('stores.enable-website');
                Route::get('/stores/{store}/storefront/create', [StorefrontController::class, 'create'])->name('stores.storefront.create');
                Route::post('/stores/{store}/storefront', [StorefrontController::class, 'store'])->name('stores.storefront.store');
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
                Route::get('/payment-method/{type}/{id}/info', [PaymentSettingsController::class, 'methodInfo'])->name('payment-settings.method-info');
                Route::post('/payment-method/{id}/assign/{type}', [PaymentSettingsController::class, 'assignStore'])->name('payment-settings.assign-store');
                Route::delete('/payment-method/{id}/unassign/{type}/{store_id}', [PaymentSettingsController::class, 'unassignStore'])->name('payment-settings.unassign-store');

                // Payment Mode
                Route::post('/payment-settings/stores/{store}/toggle-mode', [PaymentSettingsController::class, 'togglePaymentMode'])->name('payment-settings.toggle-mode');
            });

            // Invoices
            Route::middleware('permission:invoices view')->group(function () {
                Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
                Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
                Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
                Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
                Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
            });
            Route::middleware('permission:invoices edit')->group(function () {
                Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
                Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
                Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
                Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
                Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'voidInvoice'])->name('invoices.void');
                Route::post('/invoices/{invoice}/record-payment', [InvoiceController::class, 'recordPayment'])->name('invoices.record-payment');
            });
            Route::middleware('permission:invoices delete')->group(function () {
                Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
            });

            // Products
            Route::middleware('permission:products create')->group(function () {
                Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
                Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            });
            Route::middleware('permission:products view')->group(function () {
                Route::get('/products', [ProductController::class, 'index'])->name('products.index');
                Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
                Route::get('/stores/{store}/products', function (Store $store) {
                    request()->merge(['store_id' => $store->store_id]);

                    return app()->call([app(ProductController::class), 'index']);
                })->name('stores.products');
            });
            Route::middleware('permission:products edit')->group(function () {
                Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
                Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
                Route::put('/products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
                Route::post('/products/bulk-update', [ProductController::class, 'bulkUpdate'])->name('products.bulk-update');
                Route::post('/products/bulk-status', [ProductController::class, 'bulkStatus'])->name('products.bulk-status');
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
                Route::get('/stores/{store}/orders', function (Store $store) {
                    request()->merge(['store_id' => $store->store_id]);

                    return app()->call([app(OrderController::class), 'index']);
                })->name('stores.orders');
            });
            Route::middleware('permission:orders edit')->group(function () {
                Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            });
            Route::middleware('permission:orders status_update')->group(function () {
                Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
                Route::patch('/orders/{order}/payment', [OrderController::class, 'updatePaymentStatus'])->name('orders.update-payment-status');
                Route::post('/orders/{order}/accept', [OrderController::class, 'acceptOrder'])->name('orders.accept');
                Route::post('/orders/{order}/process', [OrderController::class, 'processOrder'])->name('orders.process');
                Route::post('/orders/{order}/dispatch', [OrderController::class, 'dispatchOrder'])->name('orders.dispatch');
                Route::post('/orders/{order}/deliver', [OrderController::class, 'deliverOrder'])->name('orders.deliver');
                Route::post('/orders/{order}/complete', [OrderController::class, 'completeOrder'])->name('orders.complete');
                Route::post('/orders/{order}/cancel', [OrderController::class, 'cancelOrder'])->name('orders.cancel');
                Route::post('/orders/{order}/return', [OrderController::class, 'returnOrder'])->name('orders.return');
            });
            Route::middleware('permission:orders delete')->group(function () {
                Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
            });

            // Dispatches
            Route::middleware('permission:orders view')->group(function () {
                Route::get('/dispatches', [DispatchesController::class, 'index'])->name('dispatches.index');
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

            // Accounting
            Route::middleware('permission:accounting view')->group(function () {
                Route::get('/accounting', [AccountingDashboardController::class, 'index'])->name('accounting.index');
                Route::get('/accounting/accounts', [ChartOfAccountsController::class, 'index'])->name('accounting.accounts.index');
                Route::get('/accounting/journal', [JournalController::class, 'index'])->name('accounting.journal.index');
                Route::get('/accounting/expenses', [ExpenseController::class, 'index'])->name('accounting.expenses.index');
                Route::get('/accounting/suppliers', [SupplierController::class, 'index'])->name('accounting.suppliers.index');
                Route::get('/accounting/bills', [BillController::class, 'index'])->name('accounting.bills.index');
                Route::get('/accounting/settings', [AccountingSettingsController::class, 'index'])->name('accounting.settings.index');
            });

            Route::middleware('permission:accounting accounts')->group(function () {
                Route::get('/accounting/accounts/create', [ChartOfAccountsController::class, 'create'])->name('accounting.accounts.create');
                Route::post('/accounting/accounts', [ChartOfAccountsController::class, 'store'])->name('accounting.accounts.store');
                Route::get('/accounting/accounts/{account}/edit', [ChartOfAccountsController::class, 'edit'])->name('accounting.accounts.edit');
                Route::put('/accounting/accounts/{account}', [ChartOfAccountsController::class, 'update'])->name('accounting.accounts.update');
                Route::post('/accounting/accounts/{account}/toggle', [ChartOfAccountsController::class, 'toggle'])->name('accounting.accounts.toggle');
            });

            Route::middleware('permission:accounting journal')->group(function () {
                Route::get('/accounting/journal/create', [JournalController::class, 'create'])->name('accounting.journal.create');
                Route::post('/accounting/journal', [JournalController::class, 'store'])->name('accounting.journal.store');
                Route::post('/accounting/journal/{entry}/post', [JournalController::class, 'postDraft'])->name('accounting.journal.post');
                Route::post('/accounting/journal/{entry}/reverse', [JournalController::class, 'reverse'])->name('accounting.journal.reverse');
                Route::delete('/accounting/journal/{entry}', [JournalController::class, 'destroy'])->name('accounting.journal.destroy');
            });

            Route::middleware('permission:accounting expenses')->group(function () {
                Route::get('/accounting/expenses/create', [ExpenseController::class, 'create'])->name('accounting.expenses.create');
                Route::post('/accounting/expenses', [ExpenseController::class, 'store'])->name('accounting.expenses.store');
                Route::post('/accounting/expenses/{expense}/void', [ExpenseController::class, 'void'])->name('accounting.expenses.void');
                Route::delete('/accounting/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('accounting.expenses.destroy');
            });

            Route::middleware('permission:accounting suppliers')->group(function () {
                Route::post('/accounting/suppliers', [SupplierController::class, 'store'])->name('accounting.suppliers.store');
                Route::put('/accounting/suppliers/{supplier}', [SupplierController::class, 'update'])->name('accounting.suppliers.update');
                Route::delete('/accounting/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('accounting.suppliers.destroy');
            });

            Route::middleware('permission:accounting bills')->group(function () {
                Route::get('/accounting/bills/create', [BillController::class, 'create'])->name('accounting.bills.create');
                Route::post('/accounting/bills', [BillController::class, 'store'])->name('accounting.bills.store');
                Route::post('/accounting/bills/{bill}/payments', [BillController::class, 'storePayment'])->name('accounting.bills.payments.store');
                Route::post('/accounting/bills/{bill}/void', [BillController::class, 'void'])->name('accounting.bills.void');
            });

            Route::middleware('permission:accounting settings')->group(function () {
                Route::put('/accounting/settings/mappings', [AccountingSettingsController::class, 'updateMappings'])->name('accounting.settings.mappings.update');
                Route::post('/accounting/settings/opening-balances', [AccountingSettingsController::class, 'storeOpeningBalances'])->name('accounting.settings.opening-balances.store');
                Route::post('/accounting/settings/periods/{period}/close', [AccountingSettingsController::class, 'closePeriod'])->name('accounting.settings.periods.close');
                Route::post('/accounting/settings/periods/{period}/reopen', [AccountingSettingsController::class, 'reopenPeriod'])->name('accounting.settings.periods.reopen');
            });

            Route::middleware('permission:accounting close')->group(function () {
                Route::post('/accounting/settings/years/{year}/close', [AccountingSettingsController::class, 'closeYear'])->name('accounting.settings.years.close');
            });

            // Accounting detail routes (wildcards last)
            Route::middleware('permission:accounting view')->group(function () {
                Route::get('/accounting/journal/{entry}', [JournalController::class, 'show'])->name('accounting.journal.show');
                Route::get('/accounting/expenses/{expense}', [ExpenseController::class, 'show'])->name('accounting.expenses.show');
                Route::get('/accounting/suppliers/{supplier}', [SupplierController::class, 'show'])->name('accounting.suppliers.show');
                Route::get('/accounting/bills/{bill}', [BillController::class, 'show'])->name('accounting.bills.show');
            });

            // Accounting reports
            Route::middleware('permission:accounting reports')->group(function () {
                Route::get('/accounting/reports', [AccountingReportController::class, 'index'])->name('accounting.reports.index');
                Route::get('/accounting/reports/trial-balance', [AccountingReportController::class, 'trialBalance'])->name('accounting.reports.trial-balance');
                Route::get('/accounting/reports/profit-and-loss', [AccountingReportController::class, 'profitAndLoss'])->name('accounting.reports.profit-and-loss');
                Route::get('/accounting/reports/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->name('accounting.reports.balance-sheet');
                Route::get('/accounting/reports/general-ledger', [AccountingReportController::class, 'generalLedger'])->name('accounting.reports.general-ledger');
                Route::get('/accounting/reports/ar-aging', [AccountingReportController::class, 'arAging'])->name('accounting.reports.ar-aging');
                Route::get('/accounting/reports/ap-aging', [AccountingReportController::class, 'apAging'])->name('accounting.reports.ap-aging');
                Route::get('/accounting/reports/vat-summary', [AccountingReportController::class, 'vatSummary'])->name('accounting.reports.vat-summary');
                Route::get('/accounting/reports/expense-summary', [AccountingReportController::class, 'expenseSummary'])->name('accounting.reports.expense-summary');
                Route::get('/accounting/reports/integrity', [AccountingReportController::class, 'integrity'])->name('accounting.reports.integrity');
            });

            // Bank reconciliation
            Route::middleware('permission:accounting reconcile')->group(function () {
                Route::get('/accounting/reconciliation', [BankReconciliationController::class, 'index'])->name('accounting.reconciliation.index');
                Route::post('/accounting/reconciliation', [BankReconciliationController::class, 'store'])->name('accounting.reconciliation.store');
                Route::post('/accounting/reconciliation/lines/{line}/match', [BankReconciliationController::class, 'match'])->name('accounting.reconciliation.lines.match');
                Route::post('/accounting/reconciliation/lines/{line}/unmatch', [BankReconciliationController::class, 'unmatch'])->name('accounting.reconciliation.lines.unmatch');
                Route::post('/accounting/reconciliation/lines/{line}/ignore', [BankReconciliationController::class, 'ignore'])->name('accounting.reconciliation.lines.ignore');
                Route::get('/accounting/reconciliation/{import}', [BankReconciliationController::class, 'show'])->name('accounting.reconciliation.show');
                Route::post('/accounting/reconciliation/{import}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('accounting.reconciliation.auto-match');
                Route::post('/accounting/reconciliation/{import}/complete', [BankReconciliationController::class, 'complete'])->name('accounting.reconciliation.complete');
            });

            // Support
            Route::middleware('permission:support view_tickets')->group(function () {
                Route::get('/support-messages', [SupportMessageController::class, 'index'])->name('support-messages.index');
            });
            Route::middleware('permission:support reply')->group(function () {
                Route::post('/support-messages/{supportMessage}/reply', [SupportMessageController::class, 'reply'])->name('support-messages.reply');
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
                Route::resource('/roles', RoleController::class)->except(['show'])->names([
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
                // AJAX tab endpoint for warehouse detail page
                Route::get('/warehouses/{warehouse}/tab/{tab}', [WarehouseController::class, 'loadTab'])->name('warehouses.tab');
                Route::get('/warehouses/{warehouse}/add', [ProductController::class, 'create'])->name('warehouses.products.create');
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

                // Warehouse send/receive — consolidated into transfers.create
                Route::get('/warehouses/{warehouse}/send', fn (Warehouse $warehouse) => redirect()->route('management.transfers.create', ['from_warehouse' => $warehouse->warehouse_code]))->name('warehouses.send');
                Route::get('/warehouses/{warehouse}/receive', fn (Warehouse $warehouse) => redirect()->route('management.transfers.create', ['to_warehouse' => $warehouse->warehouse_code]))->name('warehouses.receive');
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
