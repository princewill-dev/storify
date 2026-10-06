<?php

use App\Http\Controllers\Api\V1\Admin\KycApplicationController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-03 — KYC review (WS3)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| these inherit the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| `permission:admin.businesses` is the seeded gate the roadmap maps to KYC
| review. AdminApiActivityLogger rides the route so that opening a queue of
| identity documents is itself audited even before WS1's group-wide wiring
| lands; it de-dupes per request, so a later group-level application still
| writes exactly one row.
|
| `{application}` is the numeric KycApplication id, matching both the legacy
| `/office/business-kyc-applications/{application}` route and the SPA's
| `/kyc-applications/:id` full-page route.
*/

Route::middleware(['permission:admin.businesses', AdminApiActivityLogger::class])->group(function () {
    Route::get('kyc-applications', [KycApplicationController::class, 'index'])->name('kyc-applications.index');
    Route::get('kyc-applications/{application}', [KycApplicationController::class, 'show'])->name('kyc-applications.show');
    Route::post('kyc-applications/{application}/approve', [KycApplicationController::class, 'approve'])->name('kyc-applications.approve');
    Route::post('kyc-applications/{application}/reject', [KycApplicationController::class, 'reject'])->name('kyc-applications.reject');
});
