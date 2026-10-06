<?php

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ProductRules;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-14 — the update-product payload.
 *
 * The variant/digital flags default to the product's current state when the
 * request omits them, exactly as the controller computed them before calling
 * validate() — a partial update (renaming, toggling featured) keeps validating
 * against the rule set the product already lives under.
 */
class UpdateProductRequest extends FormRequest
{
    use ProductRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        $hasVariants = $this->has('has_variants') ? $this->boolean('has_variants') : (bool) $product?->has_variants;
        $isDigital = $this->has('is_digital') ? $this->boolean('is_digital') : (bool) $product?->is_digital;

        return $this->productRules(
            forUpdate: true,
            hasVariants: $hasVariants,
            isDigital: $isDigital,
            // Only demanded when the caller is turning the flag on; a partial
            // update does not need to resend variant rows.
            enablingVariants: $this->has('has_variants'),
        );
    }
}
