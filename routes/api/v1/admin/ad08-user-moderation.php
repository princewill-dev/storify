<?php

use App\Http\Controllers\Api\V1\Admin\UserModerationController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-08 — User moderation completion (WS8)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so the "admin" prefix, the "api.admin." name and the group middleware are
| inherited. Only Route:: lines belong in this file.
|
| The seven `users` URIs below are already registered by the shared
| routes/api/v1/admin.php against the thin, older UserController. Re-registering
| them here is how a module file extends an existing endpoint without touching
| the shared file: the route collection keys on method+URI, so the later
| registration replaces the earlier one and `route:list` keeps exactly one
| entry per URI. The old controller stays on disk untouched for the
| orchestrator to retire.
|
| `users/{user}` binds by account_code (User::getRouteKeyName), matching the
| legacy screens and the previous API contract.
|
| Impersonation start/stop ride `admin.users.impersonate` — the extra gate
| legacy had on "Login as user" — on top of `admin.users`.
|
| AdminApiActivityLogger rides these routes so who looked at, moderated or
| impersonated a user is itself audited; it de-dupes per request against any
| group-level wiring the orchestrator adds (WS-1).
*/

Route::middleware(['permission:admin.users', AdminApiActivityLogger::class])->group(function () {
    Route::get('users', [UserModerationController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserModerationController::class, 'show'])->name('users.show');
    Route::put('users/{user}', [UserModerationController::class, 'update'])->name('users.update');
    Route::post('users/{user}/suspend', [UserModerationController::class, 'suspend'])->name('users.suspend');
    Route::post('users/{user}/activate', [UserModerationController::class, 'activate'])->name('users.activate');
    Route::post('users/{user}/verify', [UserModerationController::class, 'verify'])->name('users.verify');
    Route::post('users/{user}/unverify', [UserModerationController::class, 'unverify'])->name('users.unverify');
    Route::post('users/{user}/reset-password', [UserModerationController::class, 'resetPassword'])->name('users.reset-password');
    Route::delete('users/{user}', [UserModerationController::class, 'destroy'])->name('users.destroy');
    Route::post('users/{user}/restore', [UserModerationController::class, 'restore'])->name('users.restore');
});

Route::middleware(['permission:admin.users', 'permission:admin.users.impersonate', AdminApiActivityLogger::class])->group(function () {
    Route::post('users/{user}/impersonate', [UserModerationController::class, 'impersonate'])->name('users.impersonate');
    Route::post('users/{user}/stop-impersonation', [UserModerationController::class, 'stopImpersonation'])->name('users.stop-impersonation');
});
