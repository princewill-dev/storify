<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-18 (admin console) — the drag-and-drop reorder payload.
 *
 * The SPA posts the visible page as `items` with the order value each row
 * should take, so a page-2 drag stays consistent with page 1 (legacy rewrote
 * order values from 0 per request, which collided across pages).
 *
 * Legacy wrapped `validate()` inside the try/catch and answered every
 * failure — including these — with `{"success": false}` and a 500. Bad
 * payloads are normal validation responses now; `distinct` reports a repeated
 * id on its second occurrence (`items.1.id`), and ids are only checked
 * against the table after validation, in the controller (anti-id-probing).
 */
final class ReorderCompanyServicesRequest extends FormRequest
{
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
