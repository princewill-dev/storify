<?php

use App\Http\Controllers\Api\V1\Management\BusinessProfileController;
use App\Http\Controllers\Api\V1\Management\BusinessRegistrationController;
use App\Http\Controllers\Api\V1\Management\OnboardingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-54 — the tail of onboarding
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file: paths and names are
| written out in full rather than wrapped in a nested group.
|
| The two screens that follow a subscription, and the two calls they make:
|
|  - `onboarding/welcome-seen` closes the `/welcome` interstitial. It is not
|    subscription-gated in practice — `next` only ever reaches `welcome` once
|    access exists — but it inherits the group's middleware like everything
|    else here, which is the correct default rather than a lucky one.
|
|  - `business` (PATCH) corrects the name signup derived from an email address,
|    plus the owner's own name and phone number. It has to stay reachable for a
|    business whose subscription has lapsed — which is why `business.` was
|    added to SubscriptionGate::EXEMPT_PREFIXES — because the details on an
|    invoice are worth being able to fix before the invoice can be paid.
|
|  - `business/registration` is how the business answers whether it is
|    registered, as what, and under which number, with the certificate as
|    evidence. It sits here rather than beside the bank accounts because it is
|    the middle step of the setup flow, not a payment setting — and because
|    `business.` is already exempt from the subscription gate, which a business
|    still finishing its setup needs.
|
| All four carry `permission:settings view`, which is the line between the owner
| and a cashier. Neither act is a cashier's: one renames the business on every
| future receipt, the other clears the owner's own onboarding checklist — and
| the Store Associate role has no `settings` permissions at all, so this needs
| no new machinery. The owner always passes it: their business role is Super
| Admin, which holds every permission.
*/

Route::middleware('permission:settings view')->group(function () {
    Route::post('onboarding/welcome-seen', [OnboardingController::class, 'welcomeSeen'])
        ->name('onboarding.welcome-seen');

    Route::patch('business', [BusinessProfileController::class, 'update'])
        ->name('business.update');

    Route::get('business/registration', [BusinessRegistrationController::class, 'show'])
        ->name('business.registration.show');
    Route::patch('business/registration', [BusinessRegistrationController::class, 'update'])
        ->name('business.registration.update');
});
