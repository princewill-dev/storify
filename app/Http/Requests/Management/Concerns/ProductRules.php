<?php

namespace App\Http\Requests\Management\Concerns;

use Illuminate\Validation\Rule;

/**
 * WS-14 — the product create/edit rule set, shared by the store and update
 * requests so the two cannot drift.
 *
 * Every legacy quirk is kept deliberately:
 *
 * - `amount` is nullable while a product is variant-driven (the variants
 *   carry the price and stock; legacy disabled the base inputs too).
 * - A variant product must actually carry variants. On update this is only
 *   demanded when the caller is turning the flag on, so partial updates
 *   (renaming, toggling featured) don't need to resend rows.
 * - `variants.*.quantity` / `variants.*.amount` use `required_with` so a row
 *   that omits them fails on its own line.
 * - `status` is not nullable: the column has no NULL state, so an explicit
 *   null must fail validation rather than blow up the insert.
 */
trait ProductRules
{
    /**
     * @return array<string, mixed>
     */
    protected function productRules(bool $forUpdate, bool $hasVariants, bool $isDigital, bool $enablingVariants = false): array
    {
        $digitalMimes = implode(',', config('digital.allowed_mimes', ['pdf', 'zip']));
        $digitalMaxKb = (int) config('digital.max_upload_kb', 102400);
        $presence = $forUpdate ? 'sometimes' : 'required';

        $rules = [
            'name' => [$presence, 'string', 'max:255'],
            'store_id' => $forUpdate ? ['sometimes', 'nullable', 'integer'] : ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // Single-SKU attributes the old API rules never accepted even
            // though the columns existed (audit #5).
            'color' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'numeric', 'min:0'],
            'size_unit_id' => ['nullable', 'integer', 'exists:size_units,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit_id' => ['nullable', 'integer', 'exists:weight_units,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'bulk_quantity' => ['nullable', 'integer', 'min:1'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'is_digital' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'has_variants' => ['sometimes', 'boolean'],
            'cod_available' => ['sometimes', 'boolean'],
            'download_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'download_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'images.*' => ['nullable', 'mimes:jpeg,jpg,png,gif,webp', 'max:20480'],
            'digital_files.*' => ['nullable', 'file', "mimes:{$digitalMimes}", "max:{$digitalMaxKb}"],
            'delete_image_ids' => ['sometimes', 'array'],
            'delete_image_ids.*' => ['integer'],
            'delete_file_ids' => ['sometimes', 'array'],
            'delete_file_ids.*' => ['integer'],
            'primary_image_id' => ['nullable', 'integer'],

            // The position, within this request's images[] payload, of a new
            // upload to make the thumbnail. An index rather than an id because
            // the browser cannot know the id of a file it has just queued for
            // upload — see ProductFormService::syncImages().
            'primary_new_image_index' => ['nullable', 'integer', 'min:0'],
        ];

        if ($hasVariants) {
            // Variants carry the price and stock: base amount/quantity are not
            // required while the product is variant-driven (legacy disabled the
            // base inputs too).
            $rules['amount'] = ['nullable', 'numeric', 'gt:0'];
            $rules['quantity'] = ['nullable', 'integer', 'min:0'];

            $variantsRequired = ! $forUpdate || $enablingVariants;
            $rules['variants'] = [$variantsRequired ? 'required' : 'sometimes', 'array', 'min:1'];
            $rules['variants.*.id'] = ['sometimes', 'integer'];
            $rules['variants.*.sku'] = ['nullable', 'string', 'max:100'];
            $rules['variants.*.size'] = ['nullable', 'numeric', 'min:0'];
            $rules['variants.*.size_unit_id'] = ['nullable', 'integer', 'exists:size_units,id'];
            $rules['variants.*.weight'] = ['nullable', 'numeric', 'min:0'];
            $rules['variants.*.weight_unit_id'] = ['nullable', 'integer', 'exists:weight_units,id'];
            $rules['variants.*.color'] = ['nullable', 'string', 'max:100'];
            $rules['variants.*.quantity'] = ['required_with:variants', 'integer', 'gt:0'];
            $rules['variants.*.amount'] = ['required_with:variants', 'numeric', 'gt:0'];
            $rules['variants.*.currency_id'] = ['nullable', 'integer', 'exists:currencies,id'];
            $rules['variants.*.status'] = ['nullable', Rule::in(['active', 'inactive'])];
            $rules['variants.*.featured'] = ['sometimes', 'boolean'];

            return $rules;
        }

        $rules['amount'] = $forUpdate ? ['sometimes', 'numeric', 'gt:0'] : ['required', 'numeric', 'gt:0'];
        $rules['quantity'] = $isDigital
            ? ['nullable', 'integer', 'min:0']
            : [$forUpdate ? 'sometimes' : 'required', 'integer', 'min:0'];

        return $rules;
    }
}
