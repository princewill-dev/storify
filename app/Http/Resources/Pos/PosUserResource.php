<?php

namespace App\Http\Resources\Pos;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The POS terminal's account block — the `user` payload login, me and
 * verify-pin return.
 *
 * Field names, types and order are frozen: three endpoints emit this block
 * and they do not agree on the tail.
 *
 * - me() renders the base shape (no `force_password_change`);
 * - login() (forLogin) appends `force_password_change` BEFORE `theme`;
 * - verify-pin (forPinSwitch) appends it AFTER `theme`.
 *
 * That disagreement is the whole reason for the two shape methods rather
 * than one flag: a single order would silently rewrite one endpoint's JSON,
 * and these payloads are asserted exactly.
 *
 * `permissions` lists the EFFECTIVE set — direct permissions plus those
 * inherited through roles — and is not re-keyed, matching every previous
 * payload's shape. It used to read getPermissionNames(), which returns only
 * direct assignments; since staff inherit everything through a role, that
 * sent an empty array and every permission-gated control hid.
 */
final class PosUserResource extends JsonResource
{
    private bool $withForcePasswordChange = false;

    private bool $forcePasswordChangeBeforeTheme = true;

    public function forLogin(): static
    {
        $this->withForcePasswordChange = true;
        $this->forcePasswordChangeBeforeTheme = true;

        return $this;
    }

    public function forPinSwitch(): static
    {
        $this->withForcePasswordChange = true;
        $this->forcePasswordChangeBeforeTheme = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
        ];

        if ($this->withForcePasswordChange && $this->forcePasswordChangeBeforeTheme) {
            $data['force_password_change'] = (bool) $user->force_password_change;
        }

        $data['theme'] = $user->theme_preference ?? 'dark';

        if ($this->withForcePasswordChange && ! $this->forcePasswordChangeBeforeTheme) {
            $data['force_password_change'] = (bool) $user->force_password_change;
        }

        return $data;
    }
}
