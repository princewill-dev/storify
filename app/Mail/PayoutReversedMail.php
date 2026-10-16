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
 * A payout that had landed was sent back to Storify.
 *
 * The hardest of the alerts to write, because the money *did* leave and then came
 * back, and a business reading it a week later will have seen it in its bank. The
 * message has to do three things at once: say the transfer was returned rather
 * than that it never happened, say where the money is now, and be clear that the
 * business is owed it again and will be paid again — because the wallet was
 * credited back the moment the provider told us, and another send is what turns
 * that into another transfer.
 *
 * No blame is assigned. The commonest reason is a bank-side recall or a receiving
 * account that could not take the funds, and both are the sort of thing that reads
 * very differently to a business depending on how the sentence is written.
 */
class PayoutReversedMail extends Mailable
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
        return $this->subject(sprintf(
            'Payout returned — %s %s',
            $this->payout->currency,
            number_format((int) $this->payout->amount_kobo / 100, 2),
        ))
            ->view('emails.custody.payout-reversed')
            ->with([
                'company' => (object) $this->company,
                'amount' => number_format((int) $this->payout->amount_kobo / 100, 2),
                'bankName' => $this->bankName,
                'accountName' => $this->payout->account_name,
                'reason' => $this->payout->failure_reason,
                'reference' => $this->payout->reference,
            ]);
    }
}
