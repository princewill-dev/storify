<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Models\RefreshToken;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TokenController extends ApiController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly ApiTokenService $tokens,
    ) {}

    /**
     * Exchange a refresh token for a new access + refresh token pair.
     * Implements rotation with reuse detection.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $rotated = $this->refreshTokens->rotate($data['refresh_token'], $request);

        if (! $rotated) {
            return $this->error('Invalid or expired refresh token.', 401);
        }

        /** @var RefreshToken $refresh */
        [$refresh, $plainRefresh] = $rotated;

        $tokenable = $refresh->tokenable;

        if (! $tokenable) {
            return $this->error('Invalid refresh token.', 401);
        }

        $abilities = (array) config("api.apps.{$refresh->app}.abilities", [$refresh->app]);

        $access = $this->tokens->issueAccessOnly($tokenable, $refresh->app);

        Log::info('api.token.refreshed', [
            'app' => $refresh->app,
            'tokenable_type' => $refresh->tokenable_type,
            'tokenable_id' => $refresh->tokenable_id,
        ]);

        return $this->ok([
            ...$access,
            'refresh_token' => $plainRefresh,
            'abilities' => $abilities,
        ], 'Token refreshed.');
    }
}
