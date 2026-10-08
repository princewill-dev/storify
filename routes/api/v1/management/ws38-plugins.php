<?php

use App\Http\Controllers\Api\V1\Management\PluginController;
use Illuminate\Support\Facades\Route;

// WS-38 — Plugins (third-party marketing and analytics integrations).
// Loaded by routes/api/v1/management.php inside its management group, so the
// prefix, name prefix and auth/audience/team middleware are already applied.
//
// `store_id` is an optional parameter on every verb rather than a path segment:
// the same endpoint configures the business-wide default (absent) and a single
// store's override (present). See PluginController::resolveStore().

Route::middleware('permission:settings plugins')->prefix('plugins')->name('plugins.')->group(function () {
    Route::get('/', [PluginController::class, 'index'])->name('index');
    Route::put('{pluginKey}', [PluginController::class, 'update'])->name('update');
    Route::delete('{pluginKey}', [PluginController::class, 'destroy'])->name('destroy');

    // Reaches out to the business's own storefront, so it is the one endpoint
    // here that earns a rate limit.
    Route::post('{pluginKey}/test', [PluginController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('test');
});
