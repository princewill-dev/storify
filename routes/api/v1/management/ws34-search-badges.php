<?php

use App\Http\Controllers\Api\V1\Management\GlobalSearchController;
use App\Http\Controllers\Api\V1\Management\ProfilePhotoController;
use App\Http\Controllers\Api\V1\Management\ShellCountsController;
use App\Http\Middleware\ForceJsonResponse;

/*
|--------------------------------------------------------------------------
| WS-34 — global search, shell badges & avatar
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and
| the auth middleware. Only Route:: lines belong in this file.
|
| `search` re-registers the same method+URI as the base SearchController and
| WS-19's CustomerSearchController. Module files load in filename order, so
| this definition (ws34 > ws19 > management.php) is the one Laravel keeps —
| exactly the hand-off WS-19's comment describes.
|
| No permission middleware on the routes themselves: search gates each result
| group on its module permission internally (so one denied group never blanks
| the palette), shell/counts zeroes counts the caller cannot see, and the
| photo endpoints only ever touch the authenticated user's own row.
*/

Route::get('search', GlobalSearchController::class)->name('search');

// One response for every nav badge, the avatar URL and the KYC/subscription
// pills — cached 15 s per user, like the legacy view composer.
Route::get('shell/counts', ShellCountsController::class)->name('shell.counts');

Route::get('profile/photo', [ProfilePhotoController::class, 'show'])->name('profile.photo.show');
// Multipart upload: without a negotiated JSON Accept a failed validation
// would redirect back (302) instead of returning the {message, errors} 422.
Route::post('profile/photo', [ProfilePhotoController::class, 'store'])
    ->middleware(ForceJsonResponse::class)
    ->name('profile.photo.store');
Route::delete('profile/photo', [ProfilePhotoController::class, 'destroy'])->name('profile.photo.destroy');
