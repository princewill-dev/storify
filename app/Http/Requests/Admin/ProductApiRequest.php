<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-15 (admin console) — shared base for the product catalogue requests.
 *
 * The platform-admin guard deliberately stays in the controller, not in
 * `authorize()`. The controller resolves these requests itself, after the
 * guard and the upload pre-checks, so the refusal order documented on
 * ProductController is preserved: 403 before any 422, and the human-readable
 * upload copy before a rule failure. A type-hinted FormRequest would be
 * validated by the container before the controller body ran — reordering
 * exactly those cases.
 */
abstract class ProductApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
