<?php

namespace App\Mail;

use App\Models\Business;
use App\Models\Payout;
use Illuminate\Mail\Mailable;

/**
 * "We have stopped paying this business, and here is why."
 *
 * ## Not queued, deliberately
 *
 * This does **not** implement `ShouldQueue`, and that is the whole point rather than
 * an oversight. `Mail::to(...)->send($mailable)` routes a `ShouldQueue` mailable into
 * the queue instead — Laravel's `Mailer::sendMailable()` checks the interface — so
 * adding it here would silently convert the one alert that has to arrive into a row
 * in a `jobs` table behind the very work that has just failed to move money. It is
 * sent straight from the queue worker's `failed()` hook, over SMTP, for the reason
 * recorded in `CustodyNotifier` and `PlatformPayoutNotifier`.
 *
 * ## What it does and does not say
 *
 * The money has not gone anywhere. Nothing here reported a lost transfer: every
 * attempt was refused and every refusal was credited back to the business's wallet,
 * where it sits visible and owed. So the mail must not read like an incident report
 * about missing funds — it reads as "sending has stopped, a person has to decide why,
 * and the amount is unchanged and still theirs".
 *
 * The failure code and reason are quoted **verbatim from the provider**, because the
 * provider's own words are the only evidence of what went wrong; paraphrasing them
 * here would put a second opinion between the operator and the thing they are
 * debugging. No account number and no bank details: the console has both, behind a
 * permission and an audit row, and mail is a channel this platform does not control.
 *
 * ## Why a transfer can be missing entirely
 *
 * `$payout` is nullable because the worst stall has no transfer in it: a provider
 * that cannot be read leaves the money in `pending_balance` and reserves nothing, so
 * the alert has a business and an amount and no reference to quote. The view says so
 * rather than printing an empty one.
 */
class AdminPayoutsStalledMail extends Mailable
{
    /**
     * @param  ?Payout  $payout  the transfer that stopped, absent when nothing was ever reserved
     * @param  array{code: string, label: string}  $blocker  why the money is standing still
     * @param  int  $failures  attempts spent, which is what stopped it
     * @param  int  $heldKobo  the business's available balance — owed, and not moving
     */
    public function __construct(
        public Business $business,
        public ?Payout $payout,
        public array $blocker,
        public int $failures,
        public int $heldKobo,
        public string $reviewUrl,
    ) {}

    public function build(): self
    {
        return $this->subject(sprintf(
            '[Payouts stopped] %s — %d failed sends',
            $this->business->name ?? 'Business #'.$this->business->id,
            $this->failures,
        ))
            ->view('emails.admin.payouts-stalled')
            ->with([
                'business' => $this->business,
                'payout' => $this->payout,
                'blocker' => $this->blocker,
                'failures' => $this->failures,
                // Formatted here, not in the view, matching every other custody
                // mail — and because a Blade that divides by 100 is a Blade that
                // is doing arithmetic in a template.
                'held' => number_format($this->heldKobo / 100, 2),
                'currency' => (string) config('custody.currency', 'NGN'),
                'reviewUrl' => $this->reviewUrl,
            ]);
    }
}
