<?php

namespace App\Http\Requests\Management\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-22 — validation for creating and editing a supplier.
 *
 * The pre-refactor controller ran one shared `validated()` helper for both
 * create and edit, so the rule set is intentionally one class used by both
 * actions (InvoiceController established the same reuse of its store request
 * for update). Splitting into a byte-identical UpdateSupplierRequest would
 * duplicate rules, not clarify them.
 *
 * The tenant guard for the bound supplier stays in the controller, after
 * validation — the layer extraction does not relocate authorisation.
 */
final class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
