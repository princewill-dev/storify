<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * WS-09 — "management.subscription".
 *
 * The API half of legacy's `CheckSubscription`: staff and trialing owners pass,
 * a business with an active subscription passes, and an owner with neither is
 * refused on every route outside the exempt list. Instead of the legacy 302 to
 * `/management/plans` this answers 403 with `message` + `code` + `redirect`,
 * which is the contract the SPA uses to move the user.
 *
 * Registration (the orchestrator wires this — see the WS-09 route module): add
 * this CLASS to the management group's middleware array, not the string
 * 'management.subscription' — bootstrap/app.php maps that name to the legacy
 * web `CheckSubscription` middleware still used by the Blade management app.
 *
 *     use App\Http\Middleware\EnsureManagementSubscription;
 *     ...
 *     Route::middleware(['auth:sanctum', 'token.audience:management', 'team.context', EnsureManagementSubscription::class])
 *         ->prefix('management') ...
 */
class EnsureManagementSubscription
{
    public function __construct(private readonly SubscriptionGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        try {
            $routeName = $request->route()?->getName();
        } catch (Throwable) {
            $routeName = null;
        }

        if ($this->gate->allows($user, $routeName)) {
            return $next($request);
        }

        $refusal = $this->gate->refusal($user);

        return response()->json([
            'message' => $refusal['message'],
            'code' => $refusal['code'],
            'redirect' => $refusal['redirect'],
        ], Response::HTTP_FORBIDDEN);
    }
}
