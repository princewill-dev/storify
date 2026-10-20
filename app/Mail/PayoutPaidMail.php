<?php

namespace App\Mail;

use App\Mail\Concerns\HasCompanyDetails;
use App\Models\Business;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The money reached the business's bank.
 *
 * The second event a business was never told about, and the more important of the
 * two: before this it learned it had been paid by looking at a bank statement. The
 * mailable carries the destination as the payout recorded it rather than as the
 * business currently names it — the transfer went to the account that was on the
 * row, and if that has since changed, saying so is the whole value of the message.
 *
 * It no longer prints what the wallet holds afterwards. That figure was the
 * business's own money explained back to it in the third person, and the payout is
 * what this mail is about.
 *
 * The figure it does print is what the bank receives, not the gross the wallet was
 * debited. The processing fee is withheld from the transfer — the rail is asked for
 * `netKobo()` — so quoting `amount_kobo` here named a number the business would not
 * find on its statement, and left it to subtract the fee itself.
 */
class PayoutPaidMail extends Mailable
{
    use HasCompanyDetails, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Business $business,
        public Payout $payout,
        public ?string $bankName = null,
    ) {
        $this->company = $this->companyDetails();
    }

    public function build(): self
    {
        // What the bank receives: the gross less the processing fee withheld from
        // the transfer. This is the figure that appears on the business's statement,
        // which is the only thing the amount line is for.
        $amount = number_format($this->payout->netKobo() / 100, 2);

        return $this->subject(sprintf(
            'Payout sent — %s %s',
            $this->payout->currency,
            $amount,
        ))
            ->view('emails.custody.payout-paid')
            ->with([
                'company' => (object) $this->company,
                'amount' => $amount,
                'bankName' => $this->bankName,
                'bankCode' => $this->payout->bank_code,
                // Named `accountNumberMasked` rather than `accountNumber`, so the
                // masking cannot be undone by a public property of the same name
                // appearing on this class later: `Mailable::buildViewData()` merges
                // public properties *over* the `with()` array, which is exactly how
                // the same key on `PayoutBlockedMail` was being defeated.
                'accountNumberMasked' => $this->maskedAccountNumber(),
                'accountName' => $this->payout->account_name,
                'reference' => $this->payout->reference,
                'paidAt' => Carbon::parse($this->payout->paid_at ?? $this->payout->updated_at)->toDayDateTimeString(),
                'sales' => $this->payout->allocations()->count(),
            ]);
    }

    /**
     * All but the last four digits.
     *
     * A payout mail is a record that money moved, and the full account number in
     * it is a number sitting in a mailbox forever. The last four are what a person
     * checks against their own statement, which is the only thing this line is for.
     */
    private function maskedAccountNumber(): string
    {
        $number = (string) $this->payout->account_number;

        return strlen($number) <= 4
            ? $number
            : str_repeat('•', strlen($number) - 4).substr($number, -4);
    }
}
