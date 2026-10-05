<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends ApiController
{
    use BuildsAuthResponses;

    public function __construct(private readonly ApiTokenService $tokens) {}

    public function impersonate(Request $request, User $user): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        if (! in_array($user->role, [User::ROLE_BUSINESS_OWNER, 'staff'], true)) {
            return $this->error('This account cannot be impersonated.', 404);
        }

        if ($user->is($admin)) {
            return $this->error('You cannot impersonate yourself.');
        }

        if ($user->status === 'deleted') {
            return $this->error('Deleted users cannot be impersonated.');
        }

        $impersonation = Impersonation::create([
            'impersonator_id' => $admin->id,
            'impersonated_id' => $user->id,
            'started_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        $pair = $this->tokens->issuePair($user, 'management', $request, [
            'impersonated',
            'impersonation:'.$impersonation->id,
        ]);

        $impersonation->update([
            'access_token_id' => (string) $user->tokens()->latest('id')->value('id'),
        ]);

        Log::info('api.admin.impersonation_started', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'impersonation_id' => $impersonation->id,
        ]);

        return $this->ok([
            ...$pair,
            'user' => $this->userPayload($user),
            'impersonation' => [
                'id' => $impersonation->id,
                'impersonator' => ['id' => $admin->id, 'name' => $admin->name],
            ],
        ], 'You are now viewing as '.$user->name.'.');
    }

    public function stop(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $abilities = (array) ($token->abilities ?? []);

        $ability = collect($abilities)->first(fn ($a) => is_string($a) && str_starts_with($a, 'impersonation:'));

        if (! $ability) {
            return $this->error('This is not an impersonation session.', 403);
        }

        $impersonation = Impersonation::find((int) substr($ability, strlen('impersonation:')));

        if (! $impersonation || $impersonation->ended_at !== null) {
            return $this->error('This impersonation session has already ended.', 404);
        }

        $impersonation->update(['ended_at' => now()]);

        $admin = $impersonation->impersonator;

        if (! $admin) {
            return $this->error('The impersonating admin could not be found.', 404);
        }

        if (method_exists($token, 'delete')) {
            $token->delete();
        }

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
