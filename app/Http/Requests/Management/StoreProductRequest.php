<?php

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ProductRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-14 — the create-product payload.
 *
 * The variant/digital flags are read from the request itself, exactly as the
 * controller read them before validating: a product being created as
 * variant-driven validates against the variant rule set, everything else
 * against the single-SKU set.
 */
class StoreProductRequest extends FormRequest
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
        return $this->productRules(
            forUpdate: false,
            hasVariants: $this->boolean('has_variants'),
            isDigital: $this->boolean('is_digital'),
        );
    }
}
