<?php

namespace App\Http\Requests\Management;

use App\Models\Store;
use App\Rules\ReservedStoreSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WS-05 — the storefront enable payload.
 *
 * The enable endpoint and the wizard share one class on purpose: both called
 * the controller's private `validated()` with the same rule set and the same
 * pre-validation slug merge, so two classes would be byte-identical.
 *
 * The slug arrives pre-checked from the availability endpoint, but a hand
 * typed "My Shop" must normalise here too — and the unique rule has to see the
 * value that will actually be written. That normalisation was `$request->merge()`
 * inside the controller's `validated()`; it is `prepareForValidation()` here,
 * running before the rules exactly as the merge ran before `validate()`.
 *
 * A legacy `template` field is not in the rules: legacy validated it
 * (`required|in:basic`) then never persisted it, and no per-store theme exists
 * in the schema or in any storefront renderer, so an inbound value is ignored
 * rather than rejected.
 *
 * The tenant guard and the already-live 422 stay in the controller body: they
 * are HTTP refusals whose message strings and order are part of the contract,
 * not `authorize()` gates.
 */
class StorefrontEnableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug($this->filled('slug')
                ? $this->string('slug')->toString()
                : $this->string('store_name')->toString()),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Store $store */
        $store = $this->route('store');

        return [
            'store_name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                new ReservedStoreSlug,
                Rule::unique('stores', 'slug')->ignore($store->getKey()),
            ],
            'is_nationwide' => ['nullable', 'boolean'],
            'nationwide_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'nationwide_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }
}
