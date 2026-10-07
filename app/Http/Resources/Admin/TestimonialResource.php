<?php

namespace App\Http\Resources\Admin;

use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-18 (admin console) — the testimonial row.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them) and `photo_url` resolves
 * BOTH photo conventions the column can hold: a real storage path (post-
 * backfill and every new upload) and a legacy `data:` URI that has not been
 * converted yet, so the office screen keeps rendering rows the backfill has
 * not reached.
 *
 * @property-read Testimonial $resource
 */
class TestimonialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Testimonial $testimonial */
        $testimonial = $this->resource;

        return [
            'id' => $testimonial->id,
            'name' => $testimonial->name,
            'occupation' => $testimonial->occupation,
            'message' => $testimonial->message,
            'photo_url' => $this->photoUrl($testimonial->photo),
            'has_photo' => (bool) $testimonial->photo,
            'is_legacy_photo' => str_starts_with((string) $testimonial->photo, 'data:'),
            'status' => $testimonial->status,
            'position' => (int) $testimonial->position,
            'created_at' => $testimonial->created_at?->toISOString(),
            'updated_at' => $testimonial->updated_at?->toISOString(),
        ];
    }

    private function photoUrl(?string $photo): ?string
    {
        if (! $photo) {
            return null;
        }

        if (str_starts_with($photo, 'data:') || str_starts_with($photo, 'http')) {
            return $photo;
        }

        return asset('storage/'.$photo);
    }
}
