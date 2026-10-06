<?php

use App\Http\Controllers\Api\V1\Admin\SupportMessageController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-16 — support inbox (WS16)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth/audience/team group, so
| these inherit the "admin" prefix, the "api.admin." name and the middleware.
| Only Route:: lines belong in this file.
|
| `admin.support` is the seeded gate the roadmap maps to the inbox (Platform
| Admin and Support Admin carry it; Finance Admin does not). AdminApiActivity
| Logger rides the group so reading customer mail is itself audited even
| before WS1's group-wide wiring lands; it de-dupes per request, so a later
| group-level application still writes exactly one row.
|
| `stats` is registered before the `{supportMessage}` binding so it can never
| resolve as a message id. `{supportMessage}` is the numeric id, matching the
| legacy `/office/support-messages/{supportMessage}` routes.
*/

Route::middleware(['permission:admin.support', AdminApiActivityLogger::class])->group(function () {
    Route::get('support-messages', [SupportMessageController::class, 'index'])
        ->name('support-messages.index');

    Route::get('support-messages/stats', [SupportMessageController::class, 'stats'])
        ->name('support-messages.stats');

    Route::get('support-messages/{supportMessage}', [SupportMessageController::class, 'show'])
        ->name('support-messages.show');

    Route::post('support-messages/{supportMessage}/reply', [SupportMessageController::class, 'reply'])
        ->name('support-messages.reply');

    Route::post('support-messages/{supportMessage}/close', [SupportMessageController::class, 'close'])
        ->name('support-messages.close');

    Route::post('support-messages/{supportMessage}/reopen', [SupportMessageController::class, 'reopen'])
        ->name('support-messages.reopen');

    Route::delete('support-messages/{supportMessage}', [SupportMessageController::class, 'destroy'])
        ->name('support-messages.destroy');
});
