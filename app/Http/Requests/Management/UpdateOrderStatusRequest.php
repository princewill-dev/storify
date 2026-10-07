<?php

namespace App\Http\Requests\Management;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The order status setter's payload — the controller's inline rules, moved
 * verbatim: any OrderStatus case value is accepted and nothing else.
 *
 * The tenant guard deliberately stays in the controller body: it is a 403 the
 * controller owns ahead of the write, not a validation gate, and it must not
 * move into middleware or authorize(). Extracting these rules means a request
 * that is both unauthorised and malformed now fails validation first (422);
 * a valid payload from an unauthorised caller still gets 403.
 */
final class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_column(OrderStatus::cases(), 'value'))],
        ];
    }
}
