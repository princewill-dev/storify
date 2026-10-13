<?php

namespace App\Mail;

use App\Mail\Concerns\HasCompanyDetails;
use App\Models\Business;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The provider has refused this bank account too many times, so we have stopped asking.
 *
 * The one alert in the family that is not about a payment. It exists because of a
 * silent state the sweep would otherwise leave behind: the destination failures
 * have crossed the ceiling, so the sweep no longer attempts this business at all
 * and its balance sits in the wallet accumulating with nothing failing loudly and
 * nothing succeeding. Every other notification here is triggered by something
 * happening; this one is triggered by the system stopping.
 *
 * It says the balance, because the figure is the argument: money is being held that
 * the business cannot receive, and it does not resolve on its own.
 */
class PayoutBlockedMail extends Mailable
{
    use HasCompanyDetails, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Business $business,
        public int $walletBalanceKobo,
        public ?string $bankName,
        public ?string $bankCode,
        public ?string $accountNumber,
        public ?string $accountName,
        public int $attempts,
        public ?string $lastReason = null,
        public ?string $currency = null,
    ) {
        $this->company = $this->companyDetails();
    }

    public function build(): self
    {
        return $this->subject('Action needed — we cannot send your payouts')
            ->view('emails.custody.payout-blocked')
            ->with([
                'company' => (object) $this->company,
                'wallet' => number_format($this->walletBalanceKobo / 100, 2),
                'currency' => $this->currency ?? config('custody.currency', 'NGN'),
                'bankName' => $this->bankName,
                'bankCode' => $this->bankCode,
                // `accountNumberMasked`, not `accountNumber`, and the name is load
                // bearing: `Mailable::buildViewData()` merges the mailable's *public
                // properties* over the `with()` array, so a key named after one of
                // them — and `$accountNumber` is one of them — would be silently
                // replaced by the unmasked value and the view would print the whole
                // number. See `PayoutPaidMail` for the same rename.
                'accountNumberMasked' => $this->maskedAccountNumber(),
                'accountName' => $this->accountName,
                'attempts' => $this->attempts,
                'reason' => $this->lastReason,
            ]);
    }

    /** All but the last four digits — see {@see PayoutPaidMail::maskedAccountNumber()}. */
    private function maskedAccountNumber(): string
    {
        $number = (string) $this->accountNumber;

        return strlen($number) <= 4
            ? $number
            : str_repeat('•', strlen($number) - 4).substr($number, -4);
    }
}
