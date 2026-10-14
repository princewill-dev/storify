<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one thing the `/welcome` page has to be able to say: it has been dealt
 * with.
 *
 * That page is the last screen of onboarding — three cards pointing at the
 * first real things to do — and the reason it can force itself is that nothing
 * else knows whether it has been seen. This endpoint is what closes that loop.
 * Until it is called, `next` keeps returning `welcome` and the SPA keeps
 * steering the owner back; the moment it is, `next` returns `dashboard` and it
 * never forces again.
 *
 * ## Called by all four exits, and deliberately not by "the page was opened"
 *
 * "Skip for now" calls it, and so does each of the three cards, *before*
 * navigating. What does not call it is the page itself on mount: an interstitial
 * that marks itself seen the instant it renders is one that appears for a
 * fraction of a second and is never seen again by a person who reloaded while
 * reading it. Seen means dealt with, so the SPA reports it at the moment a
 * choice is made, not at the moment the screen is painted.
 *
 * It lives on the business rather than the user, so a staff member's visit to
 * any page can neither consume the owner's checklist nor be sent to one.
 */
class OnboardingController extends ApiController
{
    use BuildsAuthResponses;
    use ResolvesManagementContext;

    /**
     * Mark the welcome page dealt with, and say where to go next.
     *
     * Idempotent in both directions: a second call is a no-op, and the first
     * one only ever moves the timestamp from null to a value. Nothing here
     * overwrites a timestamp that is already set, so the recorded moment is
     * genuinely when it was first dealt with rather than when it was last
     * mentioned.
     *
     * The response carries `next` for the same reason the auth endpoints do:
     * the caller has just changed an input to that computation, and asking for
     * it back in a second round trip would be a request whose only purpose is
     * to read a value this one already knows.
     *
     * The `! $business` guard below is defence in depth and is not the check
     * that actually holds: the route carries `permission:settings view`, which
     * arrives through the business's own role, so a caller without a business
     * holds no permissions at all and is refused with 403 before reaching this
     * method. That is the guarantee; this is what keeps the guarantee from
     * being one permission-name edit away from a null dereference.
     */
    public function welcomeSeen(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $business = $user->business;

        if (! $business) {
            return $this->error('Set up your business before continuing.', 422);
        }

        if ($business->welcome_seen_at === null) {
            $business->forceFill(['welcome_seen_at' => now()])->save();
        }

        return $this->ok([
            'next' => $this->nextStep($user->fresh()),
        ]);
    }
}
