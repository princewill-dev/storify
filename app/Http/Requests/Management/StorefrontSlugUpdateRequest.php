<?php

namespace App\Http\Requests\Management;

use App\Models\Store;
use App\Rules\ReservedStoreSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WS-05 — changing the public address of a storefront that is already live.
 *
 * Deliberately separate from StorefrontEnableRequest, which carries the store
 * name and the nationwide delivery settings as well and is refused outright
 * once a storefront is live. This payload has one field because the endpoint
 * changes one column: the address itself.
 *
 * Normalisation and the unique rule work exactly as they do on enable, and for
 * the same reason: the rule has to see the value that will actually be
 * written, not the "My Shop" somebody typed. The `ignore` clause is what lets
 * a store resubmit its own address without colliding with itself.
 */
class StorefrontSlugUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug($this->string('slug')->toString()),
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
            'slug' => [
                'required',
                'string',
                'max:255',
                new ReservedStoreSlug,
                Rule::unique('stores', 'slug')->ignore($store->getKey()),
            ],
        ];
    }
}
