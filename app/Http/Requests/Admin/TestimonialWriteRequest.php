<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-18 (admin console) — the testimonial create/edit payload.
 *
 * Create and edit share one contract on purpose: the controller validated
 * both actions with the same rule set. The only difference is whether `photo`
 * is required — legacy's "Leave empty to keep current photo" hint on edit.
 */
abstract class TestimonialWriteRequest extends FormRequest
{
    /**
     * The only statuses a row can hold — the filter list reuses this set so a
     * filter can never name a value the write side would not accept.
     */
    public const STATUSES = ['active', 'inactive'];

    /**
     * Edit requests make the photo optional; create keeps it required.
     */
    abstract protected function forUpdate(): bool;

    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller so its
        // order relative to route binding is unchanged.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'occupation' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
            'photo' => $this->forUpdate()
                ? ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048']
                : ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
