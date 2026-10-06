<?php

use App\Http\Controllers\Api\V1\Management\KycController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-10 — KYC Verification & Activation Gate
|--------------------------------------------------------------------------
| Business-owner KYC status and submission. Loaded by
| routes/api/v1/management.php inside its auth/audience/team group, so these
| inherit the "management" prefix and "api.management." name.
|
| WS-09 (the subscription gate) must keep `api.management.kyc.*` on its exempt
| list — a business that has not picked a plan yet still has to be able to
| complete KYC, exactly as legacy exempted management.kyc.*.
|
| The controller additionally refuses any caller who is not the business
| owner, so the policy below is a coarse gate, not the authorisation itself.
*/
Route::middleware('permission:settings view')->group(function () {
    Route::get('kyc', [KycController::class, 'show'])->name('kyc.show');
    Route::post('kyc', [KycController::class, 'store'])->name('kyc.submit');
});
