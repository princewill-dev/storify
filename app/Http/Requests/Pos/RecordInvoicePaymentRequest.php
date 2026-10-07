<?php

namespace App\Http\Requests\Pos;

use App\Enums\InvoiceStatus;
use App\Repositories\Pos\InvoiceRepository;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual-payment validation for the POS (legacy `$request->validate()` in
 * `Pos\InvoiceController::recordPayment()`), rules and messages unchanged.
 *
 * The amount ceiling is the invoice's remaining balance, so the rules need
 * the invoice — and they resolve it through the same store-scoped repository
 * the controller uses. That deliberately preserves the legacy order of
 * answers: a missing or foreign-store id still 404s before any field rule
 * runs, and for a PAID/VOID invoice the rules are skipped so the
 * controller's own 400 ("Cannot record payment on this invoice.") is not
 * pre-empted by field errors. Only then do field errors (422) surface.
 *
 * The store reachability guard itself stays on the route middleware
 * (EnsurePosStoreAccess) — it must answer 403 before validation answers 422.
 *
 * The PIN gate is NOT here: its failure keeps the controller's
 * `{success: false, message: 'Invalid PIN.'}` envelope, which a field error
 * would change.
 */
final class RecordInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $invoice = app(InvoiceRepository::class)
            ->findForStore($this->route('store'), $this->route('invoiceId'));

        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID], true)) {
            return [];
        }

        $user = $this->user();

        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$invoice->remainingBalance()],
            'payment_method' => ['required', 'in:cash,bank_transfer,cheque'],
            'pin' => $user->pos_pin ? ['required', 'string', 'size:6'] : ['nullable'],
        ];
    }
}
