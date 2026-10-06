<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Auth\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-8 — the management app's "Return to Admin" (impersonation stop).
 *
 * `Api\V1\Auth\ImpersonationController@stop` already serves this flow and is
 * an existing controller this workstream must not edit, but it only writes a
 * `Log::info` line: the legacy `Admin\UserController@stopImpersonate` wrote a
 * `user_impersonation_stopped` ActivityLog row on every end, and the WS-8
 * acceptance criterion ("every one of the ten `user_*` audit actions writes an
 * ActivityLog row") carries that forward — the management banner is the
 * primary exit path, so the row has to be written here.
 *
 * This sibling is registered over the same method+URI from
 * routes/api/v1/management/ad08-impersonation-stop.php (management.php loads
 * after auth.php, so the later registration replaces the earlier one in the
 * route collection and `route:list` keeps one entry with the same name). The
 * response contract the management SPA consumes is byte-for-byte the same —
 * the admin token pair plus the admin's user payload — with the audit row
 * added, and the admin lookup moved *before* the session is ended so a
 * missing impersonator leaves the session open instead of silently closing it
 * with nothing to return.
 */
class ImpersonationStopController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(private readonly ApiTokenService $tokens) {}

    public function stop(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $abilities = (array) ($token->abilities ?? []);

        $ability = collect($abilities)->first(
            fn ($ability) => is_string($ability) && str_starts_with($ability, Impersonation::ABILITY_PREFIX)
        );

        if (! $ability) {
            return $this->error('This is not an impersonation session.', 403);
        }

        $impersonation = Impersonation::find((int) substr($ability, strlen(Impersonation::ABILITY_PREFIX)));

        if (! $impersonation || $impersonation->ended_at !== null) {
            return $this->error('This impersonation session has already ended.', 404);
        }

        $admin = $impersonation->impersonator;

        if (! $admin) {
            return $this->error('The impersonating admin could not be found.', 404);
        }

        DB::transaction(function () use ($impersonation, $user, $admin, $token) {
            $impersonation->update(['ended_at' => now()]);

            // Revoke the impersonated access token so the handed-off session
            // cannot keep acting after the admin returns.
            if (method_exists($token, 'delete')) {
                $token->delete();
            }

            // Legacy's row: actor is the admin, subject the impersonated user,
            // so the feed reads "… stopped impersonating …" (the resolution
            // puts it on the user's business, exactly as the legacy write did).
            ActivityRecorder::record(
                action: 'user_impersonation_stopped',
                description: "{$admin->name} stopped impersonating {$user->name}",
                subject: $user,
                metadata: ['impersonation_id' => $impersonation->id],
                actor: $admin,
            );
        });

        $pair = $this->tokens->issuePair($admin, 'admin', $request);

        Log::info('api.admin.impersonation_stopped', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'impersonation_id' => $impersonation->id,
        ]);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($admin),
        ], 'Returned to admin.');
    }
}
