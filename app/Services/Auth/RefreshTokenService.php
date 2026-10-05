<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RefreshTokenService
{
    /**
     * Issue a new refresh token family seed and return the plaintext value.
     */
    public function issue(Model $tokenable, string $app, Request $request): string
    {
        $plain = Str::random(64);

        RefreshToken::create([
            'tokenable_type' => $tokenable::class,
            'tokenable_id' => $tokenable->getKey(),
            'app' => $app,
            'family_id' => (string) Str::uuid(),
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addDays((int) config("api.apps.{$app}.refresh_ttl_days", 30)),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'ip_address' => $request->ip(),
        ]);

        return $plain;
    }

    /**
     * Rotate a refresh token. Returns the new model and plaintext value,
     * or null when invalid, expired, or reused (reuse revokes the family).
     *
     * @return array{0: RefreshToken, 1: string}|null
     */
    public function rotate(string $plain, Request $request): ?array
    {
        $token = RefreshToken::where('token_hash', $this->hash($plain))->first();

        if (! $token) {
            return null;
        }

        // Reuse of a rotated/revoked token — revoke the whole family.
        if ($token->isRevoked()) {
            RefreshToken::where('family_id', $token->family_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return null;
        }

        if ($token->isExpired()) {
            return null;
        }

        $newPlain = Str::random(64);

        $new = RefreshToken::create([
            'tokenable_type' => $token->tokenable_type,
            'tokenable_id' => $token->tokenable_id,
            'app' => $token->app,
            'family_id' => $token->family_id,
            'token_hash' => $this->hash($newPlain),
            'expires_at' => now()->addDays((int) config("api.apps.{$token->app}.refresh_ttl_days", 30)),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'ip_address' => $request->ip(),
        ]);

        $token->update([
            'revoked_at' => now(),
            'replaced_by_id' => $new->id,
        ]);

        return [$new, $newPlain];
    }

    public function revoke(string $plain): void
    {
        RefreshToken::where('token_hash', $this->hash($plain))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllFor(Model $tokenable, ?string $app = null): int
    {
        return RefreshToken::where('tokenable_type', $tokenable::class)
            ->where('tokenable_id', $tokenable->getKey())
            ->when($app, fn ($q) => $q->where('app', $app))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
