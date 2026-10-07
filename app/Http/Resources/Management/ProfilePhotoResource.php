<?php

namespace App\Http\Resources\Management;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-34 — the avatar shape returned by GET/POST/DELETE management/profile/photo.
 *
 * @property User $resource
 */
class ProfilePhotoResource extends JsonResource
{
    /**
     * @return array{photo_url: string, has_photo: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            // Falls back to the Gravatar-style placeholder when no upload
            // exists, matching `$user->photoUrl()` in the legacy header.
            'photo_url' => $this->resource->photoUrl(),
            'has_photo' => (bool) $this->resource->photo_path,
        ];
    }
}
