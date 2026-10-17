<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\Payout;
use App\Services\Payments\Payouts\TransferOutcome;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;

/**
 * "A transfer is out, and the provider will not say whether it landed."
 *
 * ## Not queued, deliberately
 *
 * This does **not** implement `ShouldQueue`, for the reason {@see AdminPayoutsStalledMail}
 * records: `Mail::to(...)->send()` routes a `ShouldQueue` mailable into the queue
 * instead, which would put the news of an unconfirmed transfer behind the very work
 * that is not moving. It is sent over SMTP from the queue worker, while no request is
 * waiting on anything.
 *
 * ## What this must say, and what it must never say
 *
 * **It must not be {@see AdminPayoutsStalledMail} with different words.** That mail is
 * for a provider that answered clearly and refused, and every reassurance it offers is
 * true only of that case: "each refusal returned the money to their wallet", "the money
 * never left their wallet", and a button offering to "send it again immediately". Here
 * the provider has said nothing at all and the transfer **may have landed**, so a
 * resend is how a business gets paid twice — the exact failure the `indeterminate`
 * design in {@see TransferOutcome} exists to prevent.
 *
 * So the one instruction this mail carries is to **establish the truth at the provider
 * before resending**, and the button opens a screen rather than an action. The amount is
 * out of the wallet and nobody knows where it is, and the mail says that plainly
 * instead of reaching for a reassurance that is not available.
 *
 * No account number and no bank details, matching the alert beside it: mail is a channel
 * this platform does not control, and the console has both behind a permission and an
 * audit row.
 */
class AdminPayoutUnconfirmedMail extends Mailable
{
    /**
     * @param  int  $checks  how many times the provider was asked
     * @param  ?CarbonInterface  $since  when the transfer was sent
     */
    public function __construct(
        public Business $business,
        public Payout $payout,
        public int $checks,
        public ?CarbonInterface $since,
        public string $reviewUrl,
    ) {}

    public function build(): self
    {
        // Formatted here rather than in the view, matching every other custody mail —
        // and because a Blade that divides by 100 is a Blade doing arithmetic.
        $currency = (string) ($this->payout->currency ?: config('custody.currency', 'NGN'));
        $amount = number_format(((int) $this->payout->amount_kobo) / 100, 2);

        return $this->subject(sprintf(
            '[Payout unconfirmed] %s — %s %s is out and %s has not confirmed it',
            $this->business->name ?? 'Business #'.$this->business->id,
            $currency,
            $amount,
            $this->provider(),
        ))
            ->view('emails.admin.payout-unconfirmed')
            // `openFor`, not `since`: `Mailable::buildViewData()` merges this class's
            // *public properties* over whatever `with()` supplies, so a key named after
            // the `$since` property would reach the view as the raw Carbon instance and
            // print a timestamp. Same trap, same fix as `PayoutBlockedMail`'s
            // `accountNumberMasked`.
            ->with([
                'business' => $this->business,
                'payout' => $this->payout,
                'amount' => $amount,
                'currency' => $currency,
                'provider' => $this->provider(),
                'checks' => $this->checks,
                'openFor' => $this->waiting(),
                'providerStatus' => $this->providerStatus(),
                'reviewUrl' => $this->reviewUrl,
            ]);
    }

    /**
     * The provider's name, or a phrase where the row does not carry one.
     *
     * `Payout::$provider` is written from the platform's selected processor at send
     * time and is not nullable in practice, but a row from before that column existed
     * would read as an empty gap in the subject line rather than as a sentence.
     */
    private function provider(): string
    {
        $provider = trim((string) $this->payout->provider);

        return $provider !== '' ? $provider : 'the provider';
    }

    /**
     * The last state the provider reported, in its own words.
     *
     * Read from `meta.last_send`, the raw response body of the send. Both rails are
     * tried because their shapes differ: Bachs puts the state at the top level, Paystack
     * nests it under `data` and uses the top-level `status` as a success flag. Nothing
     * here prettifies the value — an operator comparing this against the provider's own
     * dashboard needs the word the provider used, not our translation of it.
     */
    private function providerStatus(): ?string
    {
        $lastSend = $this->payout->meta['last_send'] ?? null;

        if (! is_array($lastSend)) {
            return null;
        }

        foreach ([$lastSend['status'] ?? null, $lastSend['data']['status'] ?? null] as $status) {
            if (is_string($status) && $status !== '') {
                return $status;
            }
        }

        return null;
    }

    /**
     * How long this has been open, or null when the row does not say.
     *
     * `sent_at` is stamped before the request goes out, so it is the right clock for a
     * row that is `processing` even when the send itself threw — the transfer was
     * attempted then, whether or not it was acknowledged.
     */
    private function waiting(): ?string
    {
        $since = $this->since;

        if ($since === null) {
            return null;
        }

        return $since->diffForHumans(now(), true);
    }
}
