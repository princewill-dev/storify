<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-8 (admin console) — the user directory row.
 *
 * The account/status flags the moderation drawer renders plus the legacy
 * "Trial / plan name / None" Plan column. The directory passes rows in with
 * `business.activeSubscription.subscriptionPlan` eager-loaded; the moderation
 * actions shape a `fresh()` row where the relation lazy-loads exactly as it
 * did when this payload lived on the controller.
 *
 * @property-read User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'account_code' => $user->account_code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'is_verified' => (bool) $user->is_verified,
            'force_password_change' => (bool) $user->force_password_change,
            'business' => $user->business?->name,
            'business_code' => $user->business?->business_code,
            'business_id' => $user->business_id,
            'plan' => $this->planLabel($user),
            'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

    /**
     * The "Trial / plan name / None" column legacy showed.
     */
    private function planLabel(User $user): string
    {
        if ($user->trial_ends_at !== null && $user->trial_ends_at->isFuture()) {
            return 'Trial';
        }

        return $user->business?->activeSubscription?->subscriptionPlan?->name ?? 'None';
    }
}
