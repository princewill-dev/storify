<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\AdminInvitationController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-10 — Admin accounts & invitations (WS10)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so the "admin" prefix, the "api.admin." name and its middleware are
| inherited. Only Route:: lines belong in this file.
|
| `admins/{admin}` binds by account_code (User::getRouteKeyName), matching the
| rest of the admin API and the legacy screens' linkable rows.
|
| `permission:admin.admins` is the permission the seeder has carried since day
| one but that granted nothing until now; the controller additionally verifies
| the caller holds a genuine platform role (see AdminController's header).
| AdminApiActivityLogger rides these routes so who looked at or changed the
| platform admin team is itself audited; it de-dupes per request against any
| group-level wiring the orchestrator adds (WS-1).
|
| The two public `invitations/{token}` URIs below already exist in the shared
| routes/api/v1/auth.php against Api\V1\Auth\InvitationController, which this
| workstream does not own. Re-registering them here replaces the earlier entry
| in the route collection (it keys on method+URI, exactly as ad08 does for the
| `users` URIs) so this controller's state contract — 404 invalid vs an
| explicit "already accepted" — reaches the SPA. They are public: the admin
| group's auth/audience/team middleware is stripped with `withoutMiddleware()`
| and the POST keeps the shared `throttle:auth` limiter.
*/

Route::middleware(['permission:admin.admins', AdminApiActivityLogger::class])->group(function () {
    Route::get('admins', [AdminController::class, 'index'])->name('admins.index');
    Route::get('admins/roles', [AdminController::class, 'roles'])->name('admins.roles');
    Route::post('admins', [AdminController::class, 'store'])->name('admins.store');
    Route::post('admins/{admin}/resend', [AdminController::class, 'resend'])->name('admins.resend');
    Route::put('admins/{admin}', [AdminController::class, 'update'])->name('admins.update');
    Route::delete('admins/{admin}', [AdminController::class, 'destroy'])->name('admins.destroy');
});

Route::get('invitations/{token}', [AdminInvitationController::class, 'show'])
    ->withoutMiddleware(['auth:sanctum', 'token.audience:admin', 'team.context'])
    ->name('admins.invitation.show');

Route::post('invitations/{token}', [AdminInvitationController::class, 'accept'])
    ->middleware('throttle:auth')
    ->withoutMiddleware(['auth:sanctum', 'token.audience:admin', 'team.context'])
    ->name('admins.invitation.accept');
