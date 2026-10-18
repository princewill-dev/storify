<?php

use App\Http\Controllers\Auth\LogViewerHandoffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| This application is API-only. The web stack is kept for exactly three things
| that genuinely need it: the Paystack webhook (signature-verified, CSRF
| exempt), the public invoice payment page, which is emailed to people who
| have no account, and the session handoff for the log viewer.
|
| The log viewer is the reason the `web` guard exists at all. It is reached by
| typing a URL, and a top-level browser navigation cannot send an Authorization
| header — so a token, which is how every other client authenticates, is not an
| option. The authenticated admin console mints a signed URL instead; redeeming
| it establishes the session. `Authenticate` sends guests to route('login') by
| name, so that name is not decorative: renaming it breaks /logs with a 500.
| It now redirects to the admin console's own sign-in screen rather than
| rendering a second, Blade-based login form.
|
| Everything else lives under routes/api.php.
*/

// Not a login form: the named destination `Authenticate` resolves for guests.
Route::get('/login', [LogViewerHandoffController::class, 'login'])->name('login');

/*
| The signed handoff itself.
|
| Outside the package's `logs` prefix on purpose — that prefix owns
| `logs/{view?}` as a catch-all, and a static sibling under it would depend on
| registration order. The one-minute signature is the credential; the
| controller re-checks the account before logging it in.
*/
Route::get('/log-viewer/enter', LogViewerHandoffController::class)
    ->middleware('signed')
    ->name('log-viewer.enter');

Route::post('/logout', [LogViewerHandoffController::class, 'destroy'])
    ->middleware('auth:web')
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
