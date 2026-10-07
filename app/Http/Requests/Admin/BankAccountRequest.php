<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-12 — the bank-account create/edit payload.
 *
 * Create and edit share one contract on purpose: the previous controller
 * validated both actions with the same rule set (the edit form submits the
 * same fields as create), and the "leave alone when omitted" behaviour of the
 * optional fields lives in BankAccountService, not in the rules.
 *
 * `bank_name` and `account_number` are required because these rows are what
 * storefront/manual-transfer instructions surface to customers. The logo is a
 * public-disk image upload (max 2 MB) that replaces — and deletes — the
 * previous file.
 */
final class BankAccountRequest extends FormRequest
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
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
