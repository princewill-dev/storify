<?php

namespace App\Http\Requests\Management\Product\Concerns;

use Illuminate\Validation\Rule;

/**
 * The base ProductController's create/edit rule set — the array its
 * `rules(bool $forUpdate)` method built, moved verbatim so the store and update
 * requests cannot drift.
 *
 * Deliberately separate from App\Http\Requests\Management\Concerns\ProductRules
 * (the WS-14 slice behind the shared routes, served by ProductFormController):
 * that contract makes `amount`/`quantity` optional for variant-driven products,
 * demands variants when the flag is on, and accepts single-SKU attributes this
 * slice never validated. This one keeps the base slice's behaviour exactly —
 * `amount` is required even when `has_variants` is set, `variants` is always
 * `sometimes`, and the variant rows carry only the columns the base API always
 * accepted. The two contracts are not interchangeable and are not merged.
 */
trait ProductRules
{
    /**
     * @return array<string, mixed>
     */
    protected function productRules(bool $forUpdate): array
    {
        $digitalMimes = implode(',', config('digital.allowed_mimes', ['pdf', 'zip']));
        $digitalMaxKb = (int) config('digital.max_upload_kb', 102400);
        $presence = $forUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'store_id' => [$presence, 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'amount' => [$presence, 'numeric', 'gt:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bulk_quantity' => ['nullable', 'integer', 'min:1'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
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
            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.color' => ['nullable', 'string', 'max:100'],
            'variants.*.size' => ['nullable', 'numeric', 'min:0'],
            'variants.*.quantity' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.amount' => ['required_with:variants', 'numeric', 'gt:0'],
            'variants.*.status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
