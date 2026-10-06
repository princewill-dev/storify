<?php

namespace App\Http\Requests\Management\Accounting;

use App\Models\Bill;
use App\Services\Access\TenantGuard;
use App\Support\Money\Naira;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-22 — validation for recording a bill payment.
 *
 * Two deliberate details, both preserved from the controller this was lifted
 * out of:
 *
 * 1. The tenant guard runs from authorize(), i.e. before the rules. Validation
 *    now happens before the controller body, so a guard left there would have
 *    answered 422 to a crafted payload sent at another business's bill id
 *    where the pre-refactor controller answered 403 — an id-existence oracle.
 * 2. Rules are skipped while the bill cannot accept a payment at all. The
 *    controller answers those states with its own 422 ("cannot accept
 *    payments" / "no outstanding balance") before it ever reads the payload,
 *    and field errors must not pre-empt that, exactly as before.
 */
final class StoreBillPaymentRequest extends FormRequest
{
    /**
     * The payment methods this endpoint accepts, canonical for the New Bill
     * form picker (BillController::options) as well as the rule below.
     *
     * @var array<string, string>
     */
    public const PAYMENT_METHODS = [
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'card' => 'Card',
        'other' => 'Other',
    ];

    public function authorize(): bool
    {
        /** @var Bill $bill */
        $bill = $this->route('bill');

        // Same message as BillController::authorizeBill(): show/void keep the
        // guard in the controller because they carry no validation, so this
        // path must produce the identical 403.
        app(TenantGuard::class)->authorizeBusiness($bill, $this->user(), 'You do not have access to this bill.');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Bill $bill */
        $bill = $this->route('bill');

        $remaining = $bill->remainingBalanceKobo();

        if (in_array($bill->status, [Bill::STATUS_VOID, Bill::STATUS_PAID], true) || $remaining <= 0) {
            return [];
        }

        return [
            'payment_date' => ['required', 'date'],
            // Bound expressed as an exact decimal string built from kobo —
            // no float value ever represents money here.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.Naira::decimalFromKobo($remaining)],
            'payment_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')
                ->where('business_id', $bill->business_id)
                ->where('is_active', true)],
            'method' => ['required', Rule::in(array_keys(self::PAYMENT_METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
