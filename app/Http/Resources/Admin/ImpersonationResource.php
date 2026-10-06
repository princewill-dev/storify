<?php

namespace App\Http\Resources\Admin;

use App\Models\Impersonation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-8 (admin console) — impersonation start and stop payloads.
 *
 * The start response is the hand-off contract the admin SPA consumes: the
 * token pair plus `handoff.url`, a fully-formed deep link carrying the pair in
 * the URL fragment for the management app to exchange. `handoff.fragment` is
 * the key the fragment is stored under; the management app consumes the
 * fragment on load (see storify-management/src/components/ImpersonationHandoff.vue).
 * The SPA composes the full URL — the API has no configured management-app
 * origin — and passes `fragment` verbatim as the fragment key.
 *
 * The same resource shapes the stop response, which carries only the ended
 * session.
 *
 * @property-read Impersonation $resource
 */
final class ImpersonationResource extends JsonResource
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $pair = null;

    private ?User $impersonated = null;

    private ?User $admin = null;

    /**
     * Switch to the started-session shape: the pair travels in the URL
     * fragment (never sent to a server, never written to access logs) and the
     * management app exchanges it for its own session.
     *
     * @param  array<string, mixed>  $pair
     */
    public function handoff(User $impersonated, User $admin, array $pair): static
    {
        $this->impersonated = $impersonated;
        $this->admin = $admin;
        $this->pair = $pair;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Impersonation $impersonation */
        $impersonation = $this->resource;

        if ($this->pair === null || $this->impersonated === null || $this->admin === null) {
            return [
                'impersonation' => [
                    'id' => $impersonation->id,
                    'ended_at' => $impersonation->ended_at?->toISOString(),
                ],
            ];
        }

        $user = $this->impersonated;
        $admin = $this->admin;

        return [
            ...$this->pair,
            'user' => [
                'id' => $user->id,
                'account_code' => $user->account_code,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'business' => $user->business?->name,
            ],
            'impersonation' => [
                'id' => $impersonation->id,
                'impersonator' => ['id' => $admin->id, 'name' => $admin->name],
                'started_at' => $impersonation->started_at?->toISOString(),
            ],
            'handoff' => $this->handoffContract($impersonation, $this->pair),
        ];
    }

    /**
     * The deep link contract the admin SPA opens.
     *
     * @param  array<string, mixed>  $pair
     * @return array<string, mixed>
     */
    private function handoffContract(Impersonation $impersonation, array $pair): array
    {
        $payload = [
            'access_token' => $pair['access_token'],
            'refresh_token' => $pair['refresh_token'],
            'impersonation_id' => $impersonation->id,
            'expires_at' => now()->addSeconds((int) $pair['expires_in'])->toISOString(),
        ];

        return [
            'fragment' => 'impersonation',
            'payload' => $payload,
            'encoded' => rtrim(strtr(base64_encode(json_encode($payload) ?: '{}'), '+/', '-_'), '='),
        ];
    }
}
