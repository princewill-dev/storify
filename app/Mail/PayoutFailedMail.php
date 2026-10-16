<?php

namespace App\Mail;

use App\Mail\Concerns\HasCompanyDetails;
use App\Models\Business;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A payout was refused, and the money is back in the wallet.
 *
 * The message a business needs to be told rather than left to infer, and the one
 * most likely to provoke a support ticket either way. It leads with what happened
 * to the money — nothing was lost, it is in the wallet, it will be sent again —
 * because "your payout failed" on its own reads as money gone, which is the
 * opposite of the truth: the reservation was released the moment the provider
 * refused, before this mail was written.
 *
 * "Sent again" and not "sent on the next run", which is what this used to say. The
 * money goes back out at whichever comes first — the next sale collected, or the
 * five-minute run — and neither is a "run" the business has ever heard of, so naming
 * one invited a question about a schedule that is not theirs to care about.
 *
 * The provider's own words are included when there are any. A business that reads
 * "the account name does not match the account number" fixes it in an afternoon;
 * one that reads "payout failed" asks somebody.
 */
class PayoutFailedMail extends Mailable
{
    use HasCompanyDetails, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Business $business,
        public Payout $payout,
        public int $walletBalanceKobo,
        public ?string $bankName = null,
    ) {
        $this->company = $this->companyDetails();
    }

    public function build(): self
    {
        return $this->subject(sprintf(
            'Payout could not be sent — %s %s',
            $this->payout->currency,
            number_format((int) $this->payout->amount_kobo / 100, 2),
        ))
            ->view('emails.custody.payout-failed')
            ->with([
                'company' => (object) $this->company,
                'amount' => number_format((int) $this->payout->amount_kobo / 100, 2),
                'wallet' => number_format($this->walletBalanceKobo / 100, 2),
                'bankName' => $this->bankName,
                'bankCode' => $this->payout->bank_code,
                'reason' => $this->payout->failure_reason,
                'code' => $this->payout->failure_code,
                'reference' => $this->payout->reference,
            ]);
    }
}
