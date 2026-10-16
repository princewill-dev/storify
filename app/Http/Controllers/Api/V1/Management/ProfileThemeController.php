<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\UpdateThemeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The console's theme, saved against the user so it follows them.
 *
 * ## Why this is on the server at all
 *
 * A theme is client state and `localStorage` would hold it perfectly well on the
 * machine it was chosen on. It is here because the choice is meant to survive a
 * *different* machine, and only the account can do that — which is the same
 * reason the POS terminal's theme is stored here.
 *
 * The local copy in the SPA is a mirror for the first paint, not the record.
 *
 * ## No permission, deliberately
 *
 * This is the reader's own preference about their own screen. Every other
 * management route asks for an ability because it touches the business's data;
 * this touches one column on the caller's own row and nothing else. The
 * `ResolvesManagementContext::user()` call is what makes that safe — the row is
 * always the caller's, and there is no id in the payload to aim elsewhere.
 */
class ProfileThemeController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * PUT /management/profile/theme — remember the choice.
     *
     * A `PUT` rather than a `PATCH`: the body is the whole of the setting, and
     * re-submitting the same value is the same request rather than a partial
     * edit of something else.
     */
    public function update(UpdateThemeRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $theme = $request->validated()['theme'];

        $user->forceFill(['management_theme' => $theme])->save();

        return $this->ok(['theme' => $theme], 'Appearance saved.');
    }

    /**
     * GET /management/profile/theme — what is saved.
     *
     * Not used by the console's own store, which reads the value off the auth
     * payload it already has. It exists so the setting is readable on its own,
     * which is what anything outside the SPA would need and what makes the
     * endpoint testable without a login round-trip.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->ok(['theme' => $this->user($request)->management_theme ?? 'system']);
    }
}
