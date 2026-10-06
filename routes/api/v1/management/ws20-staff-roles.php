<?php

use App\Http\Controllers\Api\V1\Management\RoleParityController;
use App\Http\Controllers\Api\V1\Management\StaffParityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-20 — Staff & Roles Parity
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| Laravel keys routes by method+URI, so registering the staff and role URIs
| again here hands them to the parity controllers that replace the thin ones
| (the same technique WS-17 uses for `GET orders`). Permission middleware is
| unchanged from the declarations being replaced.
|
| `staff-options` is deliberately not `staff/options`: the shared routes file
| registers `GET staff/{staff}` before this module loads, and that wildcard
| would swallow `staff/options`. It feeds the invite/edit form with the team
| roles (plus their permission chips), stores and warehouses in one call.
*/

Route::middleware('permission:staff view')->group(function () {
    Route::get('staff', [StaffParityController::class, 'index'])->name('staff.index');
    Route::get('staff-options', [StaffParityController::class, 'options'])->name('staff.options');
    Route::get('staff/{staff}', [StaffParityController::class, 'show'])->name('staff.show');

    Route::get('roles', [RoleParityController::class, 'index'])->name('roles.index');
});

Route::middleware('permission:staff create')->group(function () {
    Route::post('staff', [StaffParityController::class, 'store'])->name('staff.store');
    Route::post('roles', [RoleParityController::class, 'store'])->name('roles.store');
});

Route::middleware('permission:staff edit')->group(function () {
    Route::put('staff/{staff}', [StaffParityController::class, 'update'])->name('staff.update');
    Route::post('staff/{staff}/resend-invite', [StaffParityController::class, 'resendInvite'])->name('staff.resend-invite');
    Route::delete('staff/{staff}/documents/{document}', [StaffParityController::class, 'destroyDocument'])->name('staff.documents.destroy');

    Route::put('roles/{role}', [RoleParityController::class, 'update'])->name('roles.update');
});

Route::middleware('permission:staff suspend')->group(function () {
    Route::post('staff/{staff}/suspend', [StaffParityController::class, 'suspend'])->name('staff.suspend');
    Route::post('staff/{staff}/activate', [StaffParityController::class, 'activate'])->name('staff.activate');
});

Route::middleware('permission:staff delete')->group(function () {
    Route::delete('staff/{staff}', [StaffParityController::class, 'destroy'])->name('staff.destroy');
    Route::delete('roles/{role}', [RoleParityController::class, 'destroy'])->name('roles.destroy');
});
