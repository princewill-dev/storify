<?php

namespace App\Http\Requests\Management\Invoice;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Access\TenantGuard;
use App\Services\Management\InvoiceService;
use App\Support\Money\Naira;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * WS-21 — manual (partial) payment, confirmed with the operator's current
 * password. The password gate is a deliberate legacy security feature (the
 * audit flagged it as such) — kept, not cloned away.
 *
 * Two ordering details are deliberate:
 *
 * 1. The tenant guard runs from authorize(), i.e. before the rules. The
 *    amount rule reads the bound invoice's remaining balance, so without this
 *    a crafted amount sent at another business's invoice would answer 422
 *    (leaking the balance in the message) where the pre-refactor controller
 *    answered 403.
 * 2. For a PAID/VOID invoice the rules are skipped: the controller answers
 *    those states with its own 422 ("Cannot record payment on a ... invoice.")
 *    before it reads the payload, and field errors must not pre-empt that,
 *    exactly as before.
 *
 * The amount-versus-remaining check runs here against the bound invoice and
 * again inside the workflow against the locked row — the locked re-check is
 * the authoritative one.
 */
final class RecordInvoicePaymentRequest extends FormRequest
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

        if ($invoice instanceof Invoice && in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID], true)) {
            return [];
        }

        // Same per-row maths the workflow and the document payload use — one
        // definition, owned by the service.
        $remainingKobo = $invoice instanceof Invoice ? app(InvoiceService::class)->remainingKobo($invoice) : 0;
        $user = $this->user();

        return [
            'amount' => ['required', 'numeric', 'min:0.01', function (string $attribute, mixed $value, Closure $fail) use ($remainingKobo) {
                if (Naira::koboFromRounded((float) $value) > $remainingKobo) {
                    $fail('Amount cannot exceed the remaining balance of ₦'.number_format(Naira::floatFromKobo($remainingKobo), 2).'.');
                }
            }],
            'payment_method' => ['required', Rule::in(['gateway', 'bank_transfer', 'check'])],
            'password' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($user) {
                if (! Hash::check($value, $user->password)) {
                    $fail('The password is incorrect.');
                }
            }],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
