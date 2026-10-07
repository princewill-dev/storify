<?php

namespace App\Http\Requests\Admin;

use App\Enums\TransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-5 (admin console) — `PATCH /api/v1/admin/transactions/{transaction}/status`.
 *
 * The override is enum-validated on purpose: `Rule::enum` only accepts the six
 * `TransactionStatus` backing values, so a value the enum does not model (the
 * test's `failed`, for example) is a 422 rather than a status the revenue
 * figures cannot interpret.
 */
final class UpdateTransactionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller, not
        // here: its order relative to route binding is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TransactionStatus::class)],
        ];
    }
}
