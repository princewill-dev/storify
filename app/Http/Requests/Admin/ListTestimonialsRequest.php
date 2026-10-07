<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-18 (admin console) — the testimonial list filters.
 *
 * The status list is the write contract's own (`TestimonialWriteRequest`), so
 * a filter value can never drift from a value the rows can hold; the sort
 * whitelist is closed here because the repository passes `sort` straight to
 * `orderBy` and an unknown column must be a 422, never a query fragment.
 */
final class ListTestimonialsRequest extends FormRequest
{
    /**
     * Whitelisted sort columns — an unknown `sort` is rejected by validation,
     * never passed to orderBy.
     */
    private const SORTS = ['position', 'created_at', 'updated_at', 'name', 'id'];

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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(TestimonialWriteRequest::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
