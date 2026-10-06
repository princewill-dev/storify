<?php

namespace App\Http\Requests\Management\Invoice;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Access\TenantGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * WS-21 — create and update validation for an invoice (one contract for both
 * routes, exactly as the pre-refactor controller's validated() helper).
 *
 * Three ordering details are deliberate:
 *
 * 1. The tenant guard runs from authorize(), i.e. before the rules, so a
 *    crafted payload sent at another business's invoice cannot turn the 403
 *    into a 422 field-error response (an id-existence oracle). The controller
 *    keeps the identical guard on every action; this copy exists only because
 *    validation now runs before the controller body.
 * 2. For a non-draft invoice the rules are skipped: the controller owns the
 *    draft-only 403 ("Only draft invoices can be edited.") and its message
 *    must not be pre-empted by validation errors, exactly as before.
 * 3. The post-validation store/customer checks run only when every field rule
 *    passed, because the pre-refactor controller threw on the first invalid
 *    field before ever reaching them.
 *
 * Legacy validated store_id/customer_id with a bare `exists:` rule, which let
 * a user attach another business's store or customer. Both are now scoped to
 * what the authenticated user can actually reach.
 */
final class StoreInvoiceRequest extends FormRequest
{
    private const ACCESS_DENIED = 'You do not have access to this invoice.';

    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        if ($invoice instanceof Invoice) {
            app(TenantGuard::class)->authorizeBusiness($invoice, $this->user(), self::ACCESS_DENIED);

            if ($this->user()->isRestrictedStaff()) {
                app(TenantGuard::class)->authorizeStoreId($this->user(), (int) $invoice->store_id, self::ACCESS_DENIED);
            }
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $invoice = $this->route('invoice');

        if ($invoice instanceof Invoice && ! $invoice->isDraft()) {
            return [];
        }

        return [
            'store_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_address' => ['nullable', 'string', 'max:500'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'save_customer' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $data = $validator->validated();
                $user = $this->user();

                if (! empty($data['store_id']) && ! $user->accessibleStores()->whereKey($data['store_id'])->exists()) {
                    $validator->errors()->add('store_id', 'The selected store is not available to your business.');

                    return;
                }

                if (! empty($data['customer_id']) && ! Customer::where('business_id', $user->business_id)->whereKey($data['customer_id'])->exists()) {
                    $validator->errors()->add('customer_id', 'The selected customer does not belong to your business.');
                }
            },
        ];
    }
}
