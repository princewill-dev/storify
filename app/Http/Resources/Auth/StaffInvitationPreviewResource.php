<?php

namespace App\Http\Resources\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public staff-invitation preview.
 *
 * Exactly the three fields this endpoint has always emitted, in the same
 * order (the accept screen and the tests read them by name). Nothing else —
 * on a public endpoint the invitation token is the only credential, so the
 * preview stays minimal and never exposes ids or status.
 *
 * `business` is the business name string (or null), not a nested object: the
 * screen greets the invitee with the team they are joining.
 *
 * @property-read User $resource
 */
final class StaffInvitationPreviewResource extends JsonResource
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
            'name' => $user->name,
            'business' => $user->business?->name,
        ];
    }
}
