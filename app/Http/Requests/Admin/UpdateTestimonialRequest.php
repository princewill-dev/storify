<?php

namespace App\Http\Requests\Admin;

/**
 * WS-18 (admin console) — `PUT /api/v1/admin/testimonials/{testimonial}`
 * payload.
 *
 * The photo is optional on edit — legacy's "Leave empty to keep current photo"
 * hint; the controller only stores a replacement when one was uploaded.
 */
final class UpdateTestimonialRequest extends TestimonialWriteRequest
{
    protected function forUpdate(): bool
    {
        return true;
    }
}
