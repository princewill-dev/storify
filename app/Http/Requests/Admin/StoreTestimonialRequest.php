<?php

namespace App\Http\Requests\Admin;

/**
 * WS-18 (admin console) — `POST /api/v1/admin/testimonials` payload.
 *
 * The photo is required on create: a testimonial card without one is not a
 * shape the home API ever renders.
 */
final class StoreTestimonialRequest extends TestimonialWriteRequest
{
    protected function forUpdate(): bool
    {
        return false;
    }
}
