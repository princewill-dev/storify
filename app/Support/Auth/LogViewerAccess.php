<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Who may read the server logs, and whether the viewer exists at all.
 *
 * Four callers have to agree on this answer: the admin console's mint endpoint,
 * the signed handoff that opens the viewer, the package's own `LogViewer::auth`
 * gate, and the capability the console uses to decide whether to render the
 * button. A role list written out in each of those would eventually disagree
 * with itself — and the failure mode is either a button that only leads to a
 * 403 or, worse, a gate looser than the door it guards.
 */
final class LogViewerAccess
{
    /** How long a minted handoff URL stays usable. */
    public const HANDOFF_TTL_SECONDS = 60;

    /**
     * The package registers no routes when it is disabled (or API-only), and a
     * build can disable it purely through the env. Asking the route table means
     * the console never offers a link to a viewer that is not there.
     */
    public static function enabled(): bool
    {
        return (bool) config('log-viewer.enabled', true)
            && ! (bool) config('log-viewer.api_only', false)
            && Route::has('log-viewer.index');
    }

    /**
     * @return list<string>
     */
    public static function allowedRoles(): array
    {
        return array_values((array) config('log-viewer.allowed_roles', [User::ROLE_SUPERADMIN]));
    }

    public static function allows(?Authenticatable $user): bool
    {
        return $user instanceof User
            && self::enabled()
            && $user->status === 'active'
            && in_array($user->role, self::allowedRoles(), true);
    }
}
