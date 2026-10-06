<?php

use App\Http\Controllers\Api\V1\Management\StoreOnboardingController;

/*
|--------------------------------------------------------------------------
| WS-02 — Store onboarding
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix and "api.management." name.
|
| The list and the create-form options carry a third path segment on purpose:
| the shared route file registers stores/{store} before feature modules load,
| so any two-segment GET under stores/ is captured by that route's bind and
| would 404 on an unknown id.
|
| POST stores/check-slug is deliberately not registered here — WS-05 already
| serves it (routes/api/v1/management/ws05-storefront-enable.php) as the slug
| endpoint shared by create/enable/wizard, which is what the roadmap asked
| for. Registering a second route on the same URI would shadow it.
*/

Route::middleware('permission:stores view')->group(function () {
    Route::get('stores/onboarding/list', [StoreOnboardingController::class, 'index'])
        ->name('stores.onboarding.list');

    Route::get('stores/{store}/finalize', [StoreOnboardingController::class, 'finalize'])
        ->name('stores.finalize');
});

Route::middleware('permission:stores create')->group(function () {
    Route::get('stores/onboarding/options', [StoreOnboardingController::class, 'createOptions'])
        ->name('stores.onboarding.options');

    Route::post('stores', [StoreOnboardingController::class, 'store'])
        ->name('stores.store');
});
