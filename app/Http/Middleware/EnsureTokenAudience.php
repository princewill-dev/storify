<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenAudience
{
    /**
     * Ensure the presented Sanctum token carries one of the allowed audiences.
     */
    public function handle(Request $request, Closure $next, string ...$audiences): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $token) {
            return response()->json(['message' => 'A bearer token is required for this endpoint.'], 401);
        }

        if ($token instanceof TransientToken) {
            return response()->json([
                'message' => 'A bearer token is required for this endpoint.',
            ], 401);
        }

        $abilities = (array) ($token->abilities ?? []);

        if (! empty(array_intersect($audiences, $abilities))) {
            return $next($request);
        }

        // Mocked/transient tokens (e.g. Sanctum::actingAs) expose abilities
        // through can() instead of an abilities property.
        if (empty($abilities)) {
            foreach ($audiences as $audience) {
                if ($token->can($audience)) {
                    return $next($request);
                }
            }
        }

        return response()->json([
            'message' => 'This token is not valid for this application.',
        ], 403);
    }
}
