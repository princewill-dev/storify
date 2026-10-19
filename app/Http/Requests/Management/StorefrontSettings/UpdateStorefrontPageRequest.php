<?php

namespace App\Http\Requests\Management\StorefrontSettings;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A page edit, including the footer/publish toggles sent on their own by the
 * management list. Every field is optional so those toggles do not have to
 * resend the whole page.
 *
 * The slug is editable and uniqueness is scoped to the store, ignoring this
 * page. Moving a published URL prints nothing and creates no redirect; the
 * editor warns about it, and the storefront's catch-all sends an old link home.
 */
final class UpdateStorefrontPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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

        $pageId = (int) $this->route('page');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('storefront_pages', 'slug')
                    ->where(fn ($query) => $query->where('store_id', $store->id))
                    ->ignore($pageId),
            ],
            'content' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'is_published' => ['sometimes', 'boolean'],
            'show_in_footer' => ['sometimes', 'boolean'],
        ];
    }
}
