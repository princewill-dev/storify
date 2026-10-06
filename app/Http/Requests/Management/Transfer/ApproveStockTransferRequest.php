<?php

namespace App\Http\Requests\Management\Transfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-15 — the per-line `approved_quantities` payload on approve.
 *
 * Resolved from the container by the controller (`app(...)`) rather than
 * type-hinted on the action: the AD-14 admin console delegates `approve` by
 * calling the management controller directly with a plain Request, and a
 * subclass-typed parameter would fail there. Container resolution runs the
 * same FormRequestServiceProvider path route dispatch uses — populate from the
 * current request, then validate — and it runs where the pre-refactor
 * `$request->validate()` call stood, i.e. after the controller's 403/409
 * guards.
 *
 * `authorize()` is true because the controller owns authorization here
 * (`authorizeTransfer` has already run when this validates); the framework
 * default of false would turn every call into a 403.
 */
final class ApproveStockTransferRequest extends FormRequest
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
            'approved_quantities' => ['sometimes', 'array'],
            'approved_quantities.*' => ['integer', 'min:1', 'max:1000000'],
        ];
    }
}
