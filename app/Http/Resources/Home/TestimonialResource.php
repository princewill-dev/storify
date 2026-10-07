<?php

namespace App\Http\Resources\Home;

use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One testimonial card on the marketing home payload.
 *
 * Field names, types and order match the payload the controller built inline.
 * `photo_url` resolves the stored path on the public disk and nothing else:
 * rows that still carry a legacy `data:` photo are the admin backfill's
 * problem, and this endpoint has always emitted the bare `asset()` URL for
 * whatever the column holds (the backfill's own test asserts exactly that).
 *
 * @property-read Testimonial $resource
 */
final class TestimonialResource extends JsonResource
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
            'photo_url' => $testimonial->photo ? asset('storage/'.$testimonial->photo) : null,
        ];
    }
}
