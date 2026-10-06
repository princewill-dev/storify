<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * WS-15 (admin console) — the create/edit product payload.
 *
 * Legacy's variant-aware rules: base amount/quantity are not required while
 * variants are on; the variant rows are. On update everything is "sometimes"
 * so a partial payload cannot wipe fields it never sent (see
 * {@see UpdateProductRequest}).
 *
 * Deliberate absences, from the controller's provenance notes: digital-file /
 * section / warehouse fields were request-only on the legacy admin
 * `ProductRequest` and never rendered by the legacy admin form, so the admin
 * API does not accept them; `is_digital` is read-only here.
 *
 * The custom messages are part of the API contract (tests assert them);
 * `quantity.gt` and `amount.gt` replace the stock framework wording, and the
 * image copy is the legacy "20 MB" translation.
 */
abstract class ProductWriteRequest extends ProductApiRequest
{
    /**
     * The legacy image limit (20 MB), kept as the single source for the rule,
     * the form-options hint and the error copy.
     */
    public const IMAGE_MAX_KB = 20480;

    /**
     * Update requests relax every required rule to "sometimes" (partial
     * updates are legal); create requests require the core fields.
     */
    abstract protected function forUpdate(): bool;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $forUpdate = $this->forUpdate();
        $presence = $forUpdate ? 'sometimes' : 'required';
        $hasVariants = $this->boolean('has_variants');

        $rules = [
            'store_id' => $forUpdate ? ['sometimes', 'integer'] : ['required', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'name' => [$presence, 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'status' => $forUpdate
                ? ['sometimes', Rule::in(['active', 'inactive'])]
                : ['required', Rule::in(['active', 'inactive'])],
            'featured' => ['sometimes', 'boolean'],
            'cod_available' => ['sometimes', 'boolean'],
            'has_variants' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'bulk_quantity' => ['nullable', 'integer', 'min:1'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
            'images' => ['sometimes', 'array'],
            'images.*' => ['nullable', 'file', 'mimes:jpeg,jpg,png,gif,webp', 'max:'.self::IMAGE_MAX_KB],
            'primary_image' => ['sometimes', 'integer', 'min:0'],
            'primary_image_id' => ['nullable', 'integer'],
            'delete_image_ids' => ['sometimes', 'array'],
            'delete_image_ids.*' => ['integer'],
        ];

        if ($hasVariants) {
            return array_merge($rules, [
                'variants' => $forUpdate ? ['sometimes', 'array'] : ['required', 'array', 'min:1'],
                'variants.*.id' => ['sometimes', 'integer'],
                'variants.*.sku' => ['nullable', 'string', 'max:100'],
                'variants.*.size' => ['nullable', 'numeric', 'min:0'],
                'variants.*.size_unit_id' => ['nullable', 'integer', 'exists:size_units,id'],
                'variants.*.weight' => ['nullable', 'numeric', 'min:0'],
                'variants.*.weight_unit_id' => ['nullable', 'integer', 'exists:weight_units,id'],
                'variants.*.color' => ['nullable', 'string', 'max:100'],
                'variants.*.quantity' => ['required_with:variants', 'integer', 'gt:0'],
                'variants.*.amount' => ['required_with:variants', 'numeric', 'gt:0'],
                'variants.*.currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
                'variants.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
                'variants.*.featured' => ['sometimes', 'boolean'],
            ]);
        }

        // Variant rows or a single SKU, never both half-required. When
        // `has_variants` is on the base quantity/amount are not required
        // (matching legacy and the model's own validator); when off, amount
        // and a positive quantity are.
        return array_merge($rules, [
            'quantity' => $forUpdate ? ['sometimes', 'integer', 'min:0'] : ['required', 'integer', 'gt:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'amount' => $forUpdate ? ['sometimes', 'numeric', 'gt:0'] : ['required', 'numeric', 'gt:0'],
            'color' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'numeric', 'min:0'],
            'size_unit_id' => ['nullable', 'integer', 'exists:size_units,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit_id' => ['nullable', 'integer', 'exists:weight_units,id'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required' => 'Quantity is required.',
            'quantity.gt' => 'Quantity must be greater than 0.',
            'amount.required' => 'Amount is required.',
            'amount.gt' => 'Amount must be greater than 0.',
            'variants.required' => 'Add at least one variant.',
            'variants.min' => 'Add at least one variant.',
            'images.*.max' => 'Each image must be 20 MB or smaller.',
            'images.*.mimes' => 'Images must be a JPEG, PNG, GIF or WebP file.',
        ];
    }
}
