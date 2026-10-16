<?php

namespace App\Mail;

use App\Mail\Concerns\HasCompanyDetails;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A customer paid, and the business's first notification of it.
 *
 * Deliberately says nothing about what happens to the money afterwards. The business
 * is told that a payment landed and where to see the details; the split, the wallet
 * and the settlement behind it are ours to run and theirs to see in the dashboard.
 * The one figure beyond the gross is the processing fee, because that is the only
 * deduction they are entitled to see on the amount a customer paid.
 *
 * Every figure arrives already formatted: the view does no arithmetic, and the
 * numbers it prints are the same accessors the dashboard prints.
 */
class WalletCreditedMail extends Mailable
{
    use HasCompanyDetails, Queueable, SerializesModels;

    /**
     * @param  string  $amount  Formatted gross, e.g. "NGN 5,000.00" — what the customer paid.
     * @param  ?string  $fee  Formatted processing fee, or null when none was taken.
     */
    public function __construct(
        public User $user,
        public string $amount,
        public ?string $fee = null,
        public ?string $storeName = null,
        public ?string $reference = null,
        public ?string $date = null,
    ) {
        $this->company = $this->companyDetails();
    }

    public function build(): self
    {
        return $this->subject('Payment received — '.$this->amount)
            ->view('emails.custody.wallet-credited')
            ->with(['company' => (object) $this->company]);
    }
}
