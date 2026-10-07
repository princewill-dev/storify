<?php

namespace App\Http\Requests\Management\Product;

use App\Http\Requests\Management\Product\Concerns\ProductRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The base ProductController's create payload — the rules it carried inline,
 * verbatim.
 *
 * Deliberately separate from App\Http\Requests\Management\StoreProductRequest
 * (the WS-14 slice behind the shared routes, served by ProductFormController):
 * that contract derives the variant/digital rule set from the request flags;
 * this one keeps the base slice's fixed rule set. The two are not
 * interchangeable.
 *
 * `store_id` stays a plain integer rule: the controller re-checks it against
 * the caller's accessible stores and answers its own 422 "Invalid store
 * selection." An `exists:` rule would both admit stores the caller cannot reach
 * and turn the deliberate anti-id-probing refusal (a foreign or deleted store
 * id must not read as "exists but forbidden") into a framework rule failure
 * with different copy.
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
        return $this->productRules(forUpdate: false);
    }
}
