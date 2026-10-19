<?php

namespace App\Http\Requests\Management\StorefrontSettings;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreStorefrontPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The rule has to see what will actually be written, not the title
        // somebody typed into the slug box.
        if ($this->has('slug')) {
            $this->merge(['slug' => Str::slug($this->string('slug')->toString())]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Store $store */
        $store = $this->route('store');

        return [
            'title' => ['required', 'string', 'max:160'],
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('storefront_pages', 'slug')
                    ->where(fn ($query) => $query->where('store_id', $store->id)),
            ],
            'content' => ['nullable', 'string', 'max:200000'],
            'is_published' => ['sometimes', 'boolean'],
            'show_in_footer' => ['sometimes', 'boolean'],
        ];
    }
}
