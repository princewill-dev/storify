<?php

namespace App\Http\Requests\Management\Product;

use App\Http\Requests\Management\Product\Concerns\ProductRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The base ProductController's update payload — the rules it carried inline
 * with `forUpdate: true`, verbatim: `name`, `store_id` and `amount` are
 * `sometimes`, everything else keeps the create rule set.
 *
 * Deliberately separate from App\Http\Requests\Management\UpdateProductRequest
 * (the WS-14 slice behind the shared routes, served by ProductFormController):
 * that contract derives the rule set from the product's current/requested
 * variant and digital flags; this one does not. The two are not
 * interchangeable.
 *
 * The product guard stays in the controller body on purpose: it is a
 * business+store check whose 403 has its own place in the refusal order, not a
 * FormRequest::authorize() concern.
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
        return $this->productRules(forUpdate: true);
    }
}
