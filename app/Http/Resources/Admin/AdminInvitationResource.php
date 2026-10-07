<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-10 (admin console) — the two shapes of the public invitation preview.
 *
 * Both carry `already_accepted` so the accept screen branches on one flag;
 * only the pending shape exposes the invitee's name and `invited_at`, and only
 * the accepted shape exposes `accepted_at` — exactly the fields the controller
 * previously built inline, in the same order (the SPA and tests read them by
 * name).
 *
 * @property-read User $resource
 */
final class AdminInvitationResource extends JsonResource
{
    private bool $accepted = false;

    /**
     * Switch to the already-accepted shape: the token is real but its status
     * has left `invited`, so the preview reports the terminal state instead of
     * the invite details. The POST's 409 body carries the same flag inline.
     */
    public function alreadyAccepted(): static
    {
        $this->accepted = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $admin */
        $admin = $this->resource;

        if ($this->accepted) {
            return [
                'already_accepted' => true,
                'email' => $admin->email,
                'accepted_at' => $admin->accepted_at?->toISOString(),
            ];
        }

        return [
            'already_accepted' => false,
            'email' => $admin->email,
            'name' => $admin->name,
            'invited_at' => $admin->invited_at?->toISOString(),
        ];
    }
}
