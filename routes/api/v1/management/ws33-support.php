<?php

use App\Http\Controllers\Api\V1\Management\SupportMessageController;

/*
|--------------------------------------------------------------------------
| WS-33 — support messaging
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| The permission strings are the legacy ones — `support view_tickets` for the
| inbox, `support reply` for the write — and both are already seeded
| (SpatiePermissionSeeder). `stats` is registered before the `{supportMessage}`
| binding so it never resolves as a message id; it feeds the sidebar badge
| until WS-34's shell-counts endpoint lands.
*/

Route::middleware('permission:support view_tickets')->group(function () {
    Route::get('support-messages/stats', [SupportMessageController::class, 'stats'])
        ->name('support-messages.stats');

    Route::get('support-messages', [SupportMessageController::class, 'index'])
        ->name('support-messages.index');

    Route::get('support-messages/{supportMessage}', [SupportMessageController::class, 'show'])
        ->name('support-messages.show');
});

Route::middleware('permission:support reply')->group(function () {
    Route::post('support-messages/{supportMessage}/reply', [SupportMessageController::class, 'reply'])
        ->name('support-messages.reply');
});
