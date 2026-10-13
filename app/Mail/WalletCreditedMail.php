<?php

namespace App\Mail;

use App\Mail\Concerns\HasCompanyDetails;
use App\Models\Business;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A sale was collected and its share credited to the business's Storify wallet.
 *
 * The first of the two events a business has never been told about. It is written
 * around the distinction that decides everything else in collect-and-pay-out: the
 * money is *credited* now and becomes *payable* when the provider settles it to
 * Storify, so the message names both figures rather than a single balance that
 * would read as immediately spendable.
 */
class WalletCreditedMail extends Mailable
{
    use HasCompanyDetails, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Business $business,
        public int $amountKobo,
        public string $currency,
        public int $pendingKobo,
        public int $availableKobo,
        public ?string $storeName = null,
        public ?string $reference = null,
    ) {
        $this->company = $this->companyDetails();
    }

    public function build(): self
    {
        return $this->subject(sprintf(
            'Payment received — %s %s',
            $this->currency,
            number_format($this->amountKobo / 100, 2),
        ))
            ->view('emails.custody.wallet-credited')
            ->with(['company' => (object) $this->company]);
    }
}
