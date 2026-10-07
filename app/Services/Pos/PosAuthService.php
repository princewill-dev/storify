<?php

namespace App\Services\Pos;

use App\Models\User;
use App\Repositories\Pos\PosAuthRepository;
use Illuminate\Support\Facades\Hash;

/**
 * The POS terminal auth workflows behind `Pos\AuthController` that touch more
 * than one step: the PIN switch, and the terminal token both sign-in paths
 * return.
 *
 * `switchUserByPin()` keeps the controller's exact resolution order. The
 * caller's own PIN is tested first and short-circuits — when it matches, no
 * staff query runs at all. Only then are the other staff accounts with a PIN
 * loaded (through the repository, business-scoped) and hashed one at a time
 * until the first match, which is materialised with `User::find()`: the
 * candidates are fetched with `id` and `pos_pin` only, while the response
 * renders name, email, role, theme and Spatie permission names, so the full
 * account is required — the same reason the controller re-fetched it.
 *
 * A match on another account issues a new terminal token but deliberately
 * does not touch the session guard: the web guard remains the caller the PIN
 * screen started from, exactly as before.
 *
 * No transaction was introduced — the original flow had none. `null` means
 * "no PIN matched", which the controller maps to its single 422; returning
 * the caller's own instance means "own PIN", its `switched: false` branch.
 */
final class PosAuthService
{
    public function __construct(private readonly PosAuthRepository $repository) {}

    /**
     * Resolve which account the submitted PIN opens: the caller's own account
     * when their PIN matches, otherwise the first other staff account in the
     * business whose PIN matches, otherwise null.
     */
    public function switchUserByPin(User $currentUser, string $pin): ?User
    {
        if ($currentUser->pos_pin && Hash::check($pin, $currentUser->pos_pin)) {
            return $currentUser;
        }

        foreach ($this->repository->otherStaffWithPin($currentUser) as $staff) {
            if (Hash::check($pin, $staff->pos_pin)) {
                return User::find($staff->id);
            }
        }

        return null;
    }

    /**
     * Mint the terminal token: Sanctum token named `pos-terminal` with the
     * single `pos` ability, no expiry — the contract the `token.audience:pos`
     * middleware and the management-side refusal both read. Name, abilities
     * and (absent) expiration are part of the contract; do not change them.
     */
    public function issueTerminalToken(User $user): string
    {
        return $user->createToken('pos-terminal', ['pos'])->plainTextToken;
    }
}
