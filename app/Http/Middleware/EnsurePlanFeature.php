<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SubscriptionGate;
use App\Support\Plans\FeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Your plan does not include this" — the toggle half of the plan gate.
 *
 * Takes the entitlement key as a parameter, so one middleware serves every
 * feature a plan can grant and adding the next one is a word on a route group
 * rather than a class:
 *
 *     Route::middleware('plan.feature:'.EntitlementRegistry::ACCOUNTING)->group(...)
 *
 * It answers `403` with `message` + `code` + `redirect` — the same envelope
 * {@see EnsureManagementSubscription} and the quota refusals use, so an SPA that
 * has learnt to read one refusal reads them all.
 *
 * ## Why this is not the subscription gate
 *
 * The subscription gate asks whether the business may use the platform at all;
 * this asks whether its plan includes one thing. They are separate because the
 * answers are separate: an Enterprise business with a lapsed card is stopped by
 * the other gate and this one says nothing, and an Entry business in good
 * standing passes the other gate and is stopped here.
 *
 * ## Staff do not bypass, and that is deliberate
 *
 * {@see SubscriptionGate} lets `isStaff()` through without
 * checking anything, because it is a *billing* gate and a cashier should not be
 * blocked from ringing up sales while the owner fixes a card. This is not that.
 * If the plan does not include accounting there is nothing behind this gate for a
 * staff accountant to reach, and letting them through would mean a downgrade
 * removed the books from the owner while leaving them open to whoever they had
 * previously invited. The divergence is the point, not an oversight.
 *
 * ## Gate before permission, where both apply
 *
 * Accounting routes carry `permission:accounting …` as well. This middleware is
 * listed first so that an owner — who holds every accounting permission by virtue
 * of being the owner — is told the useful thing ("your plan does not include
 * this, here is the plan that does") rather than `403 Unauthorized`, which
 * describes a permissions problem they do not have.
 */
class EnsurePlanFeature
{
    public function handle(Request $request, Closure $next, string $toggle): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $refusal = FeatureGate::refusal($user->business, $toggle);

        if ($refusal === null) {
            return $next($request);
        }

        return response()->json([
            'message' => $refusal['message'],
            'code' => $refusal['code'],
            'redirect' => $refusal['redirect'],
        ], Response::HTTP_FORBIDDEN);
    }
}
