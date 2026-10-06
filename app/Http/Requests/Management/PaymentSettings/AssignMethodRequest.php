<?php

namespace App\Http\Requests\Management\PaymentSettings;

use App\Http\Requests\Management\PaymentSettings\Concerns\ScopesStoreSelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 — assigning a gateway or bank to one of the user's stores.
 */
class AssignMethodRequest extends FormRequest
{
    use ScopesStoreSelection;

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
            'store_id' => ['required', 'integer', $this->accessibleStoreRule()],
        ];
    }
}
