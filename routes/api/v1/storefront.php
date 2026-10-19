<?php

use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Storefront\AccountController;
use App\Http\Controllers\Api\V1\Storefront\AddressController;
use App\Http\Controllers\Api\V1\Storefront\CartController;
use App\Http\Controllers\Api\V1\Storefront\CatalogController;
use App\Http\Controllers\Api\V1\Storefront\CheckoutController;
use App\Http\Controllers\Api\V1\Storefront\ConsentController;
use App\Http\Controllers\Api\V1\Storefront\ContentController;
use App\Http\Controllers\Api\V1\Storefront\DataRightsController;
use App\Http\Controllers\Api\V1\Storefront\DownloadController;
use App\Http\Controllers\Api\V1\Storefront\InvoicePaymentController;
use App\Http\Controllers\Api\V1\Storefront\SupportController;
use App\Http\Controllers\Api\V1\Storefront\TrackingController;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront API (customer-facing, per store)
|--------------------------------------------------------------------------
| Public browsing + guest carts (X-Guest-Token header). Customer endpoints
| use the dedicated sanctum_customer guard.
*/

// Customer account (registered first so `account` is not treated as a store slug)
//
// Tenant-less by design: the token identifies the customer row, and the
// business is that row's `business_id`. `customer.business` is what makes that
// safe — it refuses a token whose row has no business, which would otherwise
// leave every read below with no tenant to be scoped to.
Route::middleware(['auth:sanctum_customer', 'token.audience:customer', 'customer.business'])
    ->prefix('storefront/account')
    ->name('api.storefront.account.')
    ->group(function () {
        Route::get('orders', [AccountController::class, 'orders'])->name('orders.index');
        Route::get('orders/{orderNumber}', [AccountController::class, 'showOrder'])->name('orders.show');
        Route::get('downloads', [AccountController::class, 'downloads'])->name('downloads.index');
        Route::put('profile', [AccountController::class, 'updateProfile'])->name('profile.update');

        // The customer's own address book. `{address}` is read as a string and
        // resolved by the repository against this customer, so an id belonging
        // to somebody else 404s like one that does not exist. The constraint
        // keeps a literal segment from reaching the parameter if one is ever
        // added beside it.
        Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::put('addresses/{address}', [AddressController::class, 'update'])
            ->where('address', '[0-9]+')->name('addresses.update');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])
            ->where('address', '[0-9]+')->name('addresses.destroy');
        Route::post('addresses/{address}/default', [AddressController::class, 'setDefault'])
            ->where('address', '[0-9]+')->name('addresses.default');

        // Communication preferences. Only the optional types are writable; the
        // terms record is read-only because it is evidence, not a setting.
        Route::get('consents', [ConsentController::class, 'index'])->name('consents.index');
        Route::put('consents', [ConsentController::class, 'update'])->name('consents.update');

        // Data rights. The export is a document rather than a screen; the
        // deletion is a request that ends here and is worked by the business.
        Route::get('data-export', [DataRightsController::class, 'export'])->name('data-export');
        Route::post('delete', [DataRightsController::class, 'destroy'])->name('delete');
    });

// Public storefront
Route::prefix('storefront/{store}')->name('api.storefront.')->group(function () {
    Route::get('home', [CatalogController::class, 'home'])->name('home');

    // Customer accounts belong to one business, so these carry the store in
    // the path like every other storefront route. The transitional tenant-less
    // copies live in auth.php and resolve their store from the request; they
    // exist only while a storefront that predates this is still deployed and
    // are deleted once it is not.
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('register', [CustomerAuthController::class, 'register'])->name('register');
        Route::post('login', [CustomerAuthController::class, 'login'])->name('login');
        Route::post('verify-otp', [CustomerAuthController::class, 'verifyOtp'])->name('verify-otp');
        Route::post('resend-otp', [CustomerAuthController::class, 'resendOtp'])->name('resend-otp');
        Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [CustomerAuthController::class, 'resetPassword'])->name('reset-password');
    });

    // Plugin tags this storefront should run. Public because its two callers
    // have no session: the storefront SPA, and the Cloudflare Worker that
    // rewrites the raw HTML head so verification meta tags are visible to
    // crawlers that do not run JavaScript. Cached at the edge per store.
    Route::get('tracking', [TrackingController::class, 'show'])->name('tracking');

    // Site metadata for the Cloudflare Worker, which injects the crawler-facing
    // tags into the shared index.html. Public for the same reason tracking is:
    // its callers have no session. Published pages only.
    Route::get('seo', [ContentController::class, 'seo'])->name('seo');

    // Owner-written pages (About, Terms, Privacy and custom). The slug is
    // constrained because it is generated from a title and never carries
    // anything but lowercase words and hyphens.
    Route::get('pages/{slug}', [ContentController::class, 'page'])
        ->where('slug', '[a-z0-9-]+')
        ->name('pages.show');
    Route::get('products', [CatalogController::class, 'products'])->name('products.index');
    Route::get('products/{slugOrCode}', [CatalogController::class, 'show'])->name('products.show');
    Route::get('services', [CatalogController::class, 'services'])->name('services.index');
    Route::get('services/{slugOrCode}', [CatalogController::class, 'serviceShow'])->name('services.show');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
    Route::get('delivery-routes', [CatalogController::class, 'deliveryRoutes'])->name('delivery-routes');
    Route::get('payment-methods', [CheckoutController::class, 'paymentMethods'])->name('payment-methods');
    Route::post('support', [SupportController::class, 'store'])->middleware('throttle:3,10')->name('support.store');

    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
    Route::post('cart/items', [CartController::class, 'add'])->name('cart.add');
    Route::patch('cart/items/{item}', [CartController::class, 'updateItem'])->name('cart.items.update');
    Route::delete('cart/items/{item}', [CartController::class, 'removeItem'])->name('cart.items.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

    Route::post('checkout', [CheckoutController::class, 'place'])->name('checkout.place');

    // Paying for an order needs the customer who owns it, not merely the store
    // the order is in. These used to be public, which made the order number the
    // only thing between a stranger and somebody else's order — and it is the
    // wrong kind of secret, because it is printed in emails and in the URL the
    // customer is looking at. The store in the path still scopes the lookup;
    // this decides *whose* order within it.
    //
    // Tracking stays public below: /track is a deliberate feature for people
    // with no account, and it reads rather than pays.
    Route::middleware(['auth:sanctum_customer', 'token.audience:customer'])->group(function () {
        // Generic provider endpoints. The provider is a path segment rather than
        // one route per gateway, so adding a provider is a driver and a registry
        // entry and nothing here.
        //
        // {provider} is constrained to known registry keys at the route, so an
        // unknown one 404s before reaching a controller rather than being passed
        // into a lookup.
        Route::post('payments/{provider}/initialize', [CheckoutController::class, 'initializePayment'])
            ->whereIn('provider', PaymentGatewayRegistry::keys())
            ->middleware('throttle:20,1')
            ->name('payments.initialize');

        Route::post('payments/{provider}/verify', [CheckoutController::class, 'verifyPayment'])
            ->whereIn('provider', PaymentGatewayRegistry::keys())
            ->name('payments.verify');

        // Kept so the already-deployed storefront keeps working through the
        // rollout — the same handlers with the provider fixed.
        Route::post('payments/paystack/initialize', [CheckoutController::class, 'paystackInitialize'])->name('payments.paystack.initialize');
        Route::post('payments/paystack/verify', [CheckoutController::class, 'paystackVerify'])->name('payments.paystack.verify');
        Route::post('payments/bank-transfer', [CheckoutController::class, 'bankTransfer'])->name('payments.bank-transfer');
    });

    Route::get('orders/{orderNumber}', [CheckoutController::class, 'orderShow'])->name('orders.show');

    // Invoice payment. Public because the token in the emailed link *is* the
    // credential — the recipient has no account and no session. The lookup is
    // still scoped to this store's business and to the invoice's own store.
    //
    // The provider is a path segment, as it is at checkout, so adding a
    // provider stays a driver and a registry entry.
    Route::prefix('invoices/{token}')->name('invoices.')->group(function () {
        Route::get('/', [InvoicePaymentController::class, 'show'])->name('show');

        Route::post('payments/{provider}/initialize', [InvoicePaymentController::class, 'initialize'])
            ->whereIn('provider', PaymentGatewayRegistry::keys())
            ->middleware('throttle:20,1')
            ->name('payments.initialize');

        Route::post('payments/{provider}/verify', [InvoicePaymentController::class, 'verify'])
            ->whereIn('provider', PaymentGatewayRegistry::keys())
            ->name('payments.verify');

        // The slip is money paid outside the platform. It resolves only when
        // the store's own bank accounts are on offer — never under a system
        // processor, where the resolver does not return it.
        Route::post('bank-transfer', [InvoicePaymentController::class, 'bankTransfer'])
            ->name('bank-transfer');
    });

    // Tokenised digital downloads. The token in the emailed link IS the
    // credential, so these stay public and are scoped to the issuing store.
    Route::get('downloads/{token}', [DownloadController::class, 'show'])->name('downloads.show');
    Route::get('downloads/{token}/files/{file}', [DownloadController::class, 'file'])->name('downloads.file');
});
