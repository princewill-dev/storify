<?php

use App\Http\Controllers\Auth\SuperadminSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| This application is API-only. The web stack is kept for exactly three things
| that genuinely need it: the Paystack webhook (signature-verified, CSRF
| exempt), the public invoice payment page, which is emailed to people who
| have no account, and the session login for the log viewer.
|
| The log viewer is the reason the `web` guard exists at all. It is reached by
| typing a URL, and a top-level browser navigation cannot send an Authorization
| header — so a token, which is how every other client authenticates, is not an
| option. `Authenticate` sends guests here by resolving route('login') by name,
| so that name is not decorative: renaming it breaks /logs with a 500.
|
| Everything else lives under routes/api.php.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [SuperadminSessionController::class, 'show'])->name('login');

    // The account must be a superadmin, so the guessable secret is a password
    // to a real account rather than a token — worth rate limiting directly.
    Route::post('/login', [SuperadminSessionController::class, 'store'])->middleware('throttle:6,1');
});

Route::post('/logout', [SuperadminSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
| Where a provider sends the browser back after the customer pays at a till.
|
| The POS app cannot be the landing page: it is a hash-routed single-page app on
| another host, so loading it here would boot a second till inside the payment
| window and have it try to sign in. This is a page that says the payment went
| through and closes itself. The till does not depend on it — it asks the
| provider directly — so nothing breaks if a customer lingers on it or blocks
| the popup entirely.
*/
Route::view('/pos/payment-callback', 'pos.payment-callback');

require __DIR__.'/v1/home.php';
require __DIR__.'/v1/storefront.php';
