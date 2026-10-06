<?php

use App\Http\Controllers\Api\V1\Management\Subscription\GateController;
use Illuminate\Support\Facades\Route;

// WS-09 — Subscription gate, banners & trial lifecycle.
//
// Loaded by routes/api/v1/management.php inside its management group, so the
// prefix, name prefix and auth/audience/team middleware are already applied to
// the route below.
//
// WIRING (orchestrator): the gate itself must cover the whole management group,
// not just this module — add the middleware CLASS to the middleware array of
// that group in routes/api/v1/management.php:
//
//     use App\Http\Middleware\EnsureManagementSubscription;
//     ...
//     ->middleware(['auth:sanctum', 'token.audience:management', 'team.context', EnsureManagementSubscription::class])
//
// Do NOT wire it as the string 'management.subscription'. bootstrap/app.php
// already maps that name to the legacy web `CheckSubscription` middleware,
// which the Blade management app (routes/v1/management.php) still uses; a
// route-file alias overriding it would change the legacy app's behaviour and
// a route-cached deployment would silently fall back to the legacy class. The
// class reference needs no alias and survives `route:cache`.
//
// The exempt-route list lives in App\Services\SubscriptionGate::EXEMPT_ROUTES.
Route::get('subscription/status', [GateController::class, 'status'])
    ->middleware('permission:dashboard view')
    ->name('subscription.status');
