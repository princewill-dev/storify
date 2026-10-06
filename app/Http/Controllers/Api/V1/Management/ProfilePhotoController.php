<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WS-34 — avatar upload and removal for the signed-in user.
 *
 * The legacy profile screen accepted a `photo` (jpeg/png/jpg/webp, max 2 MB),
 * replaced it by deleting the old file from the public disk, and a
 * `remove_photo` flag deleted the file and fell back to a Gravatar-style
 * placeholder. That behaviour lives on `PUT management/profile` in legacy, but
 * that route here is `ManagementAuthController@updateProfile` — owned by the
 * auth workstream — so the photo gets its own resource beside it instead of
 * being bolted onto a controller this workstream does not own.
 *
 * `photo_url` (with the Gravatar fallback) is also returned by
 * `GET management/shell/counts`, which the shell avatar reads; the `me` /
 * login payloads still lack it because `BuildsAuthResponses::userPayload` is
 * shared with the auth workstream — add
 * `'photo_url' => $user->photoUrl()` there when wiring the final shell.
 *
 * Legacy deleted the old file *before* storing the replacement, so a failed
 * write lost the old photo. Here the new file is written and the row updated
 * first, and only then is the old file removed.
 */
class ProfilePhotoController extends ApiController
{
    use ResolvesManagementContext;

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->ok($this->payload($user));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        $user = $this->user($request);
        $previous = $user->photo_path;

        $path = $request->file('photo')->store('photos', 'public');

        $user->update(['photo_path' => $path]);

        // Only once the new path is committed does the old file go away.
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        Log::info('api.management.profile_photo_updated', [
            'user_id' => $user->id,
            'replaced' => $previous !== null,
        ]);

        return $this->ok($this->payload($user->fresh()), 'Profile photo updated.');
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->photo_path) {
            Storage::disk('public')->delete($user->photo_path);

            $user->update(['photo_path' => null]);

            Log::info('api.management.profile_photo_removed', [
                'user_id' => $user->id,
            ]);
        }

        return $this->ok($this->payload($user->fresh()), 'Profile photo removed.');
    }

    /**
     * @return array{photo_url: string, has_photo: bool}
     */
    private function payload(User $user): array
    {
        return [
            // Falls back to the Gravatar-style placeholder when no upload
            // exists, matching `$user->photoUrl()` in the legacy header.
            'photo_url' => $user->photoUrl(),
            'has_photo' => (bool) $user->photo_path,
        ];
    }
}
