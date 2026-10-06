<?php

use App\Http\Controllers\Api\V1\Management\Pos\OrderSourceController;
use App\Http\Controllers\Api\V1\Management\Pos\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-17 — POS Oversight & Sessions
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/management.php inside its auth/audience/team group,
| so these inherit the "management" prefix, the "api.management." name and the
| auth middleware. Only Route:: lines belong in this file.
|
| Permission parity with the legacy routes/v1/management.php POS block:
|   pos view_history  — every read (list, detail, per-store log, overview)
|   pos open_session  — open
|   pos close_session — close
|   stores settings   — enable (legacy gated pos/enable behind stores settings)
|
| Terminal deep-links are the SPA's job (VITE_POS_URL); the dead
| config('pos.link') the legacy views rendered as href="" is not resurrected.
|
| GET orders re-registers the thin OrderController@index declared earlier in
| routes/api/v1/management.php (Laravel keys routes by method+URI, so the later
| registration replaces it) with the legacy `source` filter plus pos_session_id
| — see OrderSourceController. Any future module that registers GET orders must
| keep those fields, or POS sales lose the data their badge and filter read.
*/

Route::middleware('permission:pos view_history')->group(function () {
    Route::get('pos/overview', [SessionController::class, 'overview'])->name('pos.overview');
    Route::get('pos/sessions', [SessionController::class, 'index'])->name('pos.sessions.index');
    Route::get('pos/sessions/{session}', [SessionController::class, 'show'])->name('pos.sessions.show');

    Route::get('stores/{store}/pos/sessions', [SessionController::class, 'storeIndex'])
        ->name('stores.pos.sessions.index');
    Route::get('stores/{store}/pos/sessions/{session}', [SessionController::class, 'storeShow'])
        ->name('stores.pos.sessions.show');
});

Route::middleware('permission:pos open_session')->group(function () {
    Route::post('stores/{store}/pos/open', [SessionController::class, 'open'])->name('pos.open');
});

Route::middleware('permission:pos close_session')->group(function () {
    Route::post('stores/{store}/pos/close', [SessionController::class, 'close'])->name('pos.close');
});

Route::middleware('permission:stores settings')->group(function () {
    Route::post('stores/{store}/pos/enable', [SessionController::class, 'enable'])->name('pos.enable');
});

Route::middleware('permission:orders view')->group(function () {
    Route::get('orders', [OrderSourceController::class, 'index'])->name('orders.index');
});
