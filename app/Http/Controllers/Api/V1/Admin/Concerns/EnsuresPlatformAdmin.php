<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Models\User;

/**
 * WS-5 — platform-console guard shared by the order-oversight controllers.
 *
 * The `token.audience:admin` middleware plus the `admin.orders` / permission
 * gates are not sufficient on their own: every business's in-business "Super
 * Admin" role is seeded with the full permission bundle, `admin.*` names
 * included, so a business-scoped account holding a leaked admin-audience
 * token would satisfy the permission gate and read every tenant's orders.
 * Platform office accounts (AdminAuthController only signs in superadmin /
 * admin) always pass. Mirrors the guard WS-1 put on the activity-log viewer.
 */
trait EnsuresPlatformAdmin
{
    protected function authorizePlatformAdmin(): void
    {
        $user = request()->user();

        abort_unless(
            $user instanceof User && in_array($user->role, [User::ROLE_SUPERADMIN, User::ROLE_ADMIN], true),
            403,
            'This endpoint is restricted to platform administrators.',
        );
    }
}
