<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public admin-invitation preview: the invitee's email and nothing else,
 * the same single field this endpoint has always returned. The admin screens
 * only need the address to confirm who is accepting, and widening a public
 * payload would be a new disclosure — the single field is deliberate.
 *
 * @property-read User $resource
 */
final class AdminInvitationPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'email' => $user->email,
        ];
    }
}
