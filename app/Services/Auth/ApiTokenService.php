<?php

namespace App\Services\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ApiTokenService
{
    public function __construct(private readonly RefreshTokenService $refreshTokens) {}

    /**
     * Issue an access + refresh token pair for the given application.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function issuePair(Model $tokenable, string $app, Request $request, array $extraAbilities = []): array
    {
        $ttlMinutes = (int) config("api.apps.{$app}.access_ttl_minutes", 120);
        $abilities = array_merge(
            (array) config("api.apps.{$app}.abilities", [$app]),
            $extraAbilities
        );

        $access = $tokenable->createToken(
            $app.'-access',
            array_values(array_unique($abilities)),
            now()->addMinutes($ttlMinutes)
        );

        $refresh = $this->refreshTokens->issue($tokenable, $app, $request);

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh,
            'expires_in' => $ttlMinutes * 60,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Issue an access token only (used when rotating a refresh token).
     */
    public function issueAccessOnly(Model $tokenable, string $app, array $extraAbilities = []): array
    {
        $ttlMinutes = (int) config("api.apps.{$app}.access_ttl_minutes", 120);
        $abilities = array_merge(
            (array) config("api.apps.{$app}.abilities", [$app]),
            $extraAbilities
        );

        $access = $tokenable->createToken(
            $app.'-access',
            array_values(array_unique($abilities)),
            now()->addMinutes($ttlMinutes)
        );

        return [
            'access_token' => $access->plainTextToken,
            'expires_in' => $ttlMinutes * 60,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Revoke the access token used for the current request.
     */
    public function revokeCurrentAccessToken(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
    }

    /**
     * Revoke every access token for the given model, optionally scoped to an app.
     */
    public function revokeAllAccessTokens(Model $tokenable, ?string $app = null): int
    {
        $query = $tokenable->tokens();

        if ($app) {
            $query->where('name', $app.'-access');
        }

        return $query->delete();
    }
}
