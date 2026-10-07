<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Repositories\Admin\AdminAccountRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-10 (admin console) — the admin directory row / mutation payload.
 *
 * `is_self`, `is_protected` and `can_manage` are computed server-side so the
 * SPA never has to guess why an action is unavailable; they need the viewer,
 * which the controller passes in (the directory passes the list's viewer, the
 * mutations the acting admin). Field names, types and order are what the
 * endpoint emitted before the extraction.
 *
 * @property-read User $resource
 */
final class AdminAccountResource extends JsonResource
{
    public function __construct(User $admin, private readonly ?User $viewer = null)
    {
        parent::__construct($admin);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $admin */
        $admin = $this->resource;
        $roleNames = $admin->roles->pluck('name')->values()->all();
        $isSuperadmin = $admin->role === User::ROLE_SUPERADMIN;

        return [
            'id' => $admin->id,
            'account_code' => $admin->account_code,
            'name' => $admin->name,
            // Legacy rendered the literal "Pending setup" for an invitee who
            // had not chosen a name yet.
            'display_name' => $this->displayName($admin) ?? 'Pending setup',
            'email' => $admin->email,
            'phone' => $admin->phone,
            'role' => $admin->role,
            'is_superadmin' => $isSuperadmin,
            'status' => $admin->status,
            'is_verified' => (bool) $admin->is_verified,
            'force_password_change' => (bool) $admin->force_password_change,
            'roles' => $roleNames,
            'role_name' => $isSuperadmin ? AdminAccountRepository::SUPER_ADMIN_ROLE : ($roleNames[0] ?? 'Admin'),
            'invited_at' => $admin->invited_at?->toISOString(),
            'accepted_at' => $admin->accepted_at?->toISOString(),
            'last_login_at' => $admin->last_login_at?->toISOString(),
            'created_at' => $admin->created_at?->toISOString(),
            'is_self' => $this->viewer !== null && $this->viewer->id === $admin->id,
            'is_protected' => $isSuperadmin,
            'can_manage' => $this->viewer !== null && $this->viewer->id !== $admin->id && ! $isSuperadmin,
        ];
    }

    /**
     * Display name for an account that may still be "Pending setup" (legacy
     * created admins with an empty name and rendered that label literally).
     * The controller carries the same normalisation for its update message,
     * which falls back to the email rather than this label.
     */
    private function displayName(User $admin): ?string
    {
        $name = trim((string) $admin->name);

        return $name === '' ? null : $name;
    }
}
