<?php

namespace App\Services\Payments\Custody;

use App\Jobs\SettlePayouts;
use App\Mail\AdminPayoutsStalledMail;
use App\Mail\AdminPayoutUnconfirmedMail;
use App\Models\Business;
use App\Models\Payout;
use App\Models\User;
use App\Support\SpaUrls;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the platform's operators that a business's money has stopped moving.
 *
 * The counterpart to {@see CustodyNotifier}, and separate from it for one reason:
 * that class notifies a *business*, through a `business_notifications` row and a
 * mail to the owner, and every message it writes is addressed to the person whose
 * money it is. Nothing it can say is addressed to us, and the gap that left was
 * real — when the platform gave up on a payout, the business was told and the
 * operator was not. A business complaining to support was how the platform
 * discovered it had stopped paying somebody.
 *
 * ## The recipients, and why this is not a notification table
 *
 * Every active platform operator, falling back to the configured platform address.
 * Not just the superadmins, which is what the rest of the admin mail uses, and
 * `recipients()` carries the reasoning. This does not invent an admin notification table to sit beside
 * `business_notifications`, because there is nothing here that needs to be read
 * back: the authoritative record of a stalled payout is the payout itself, and the
 * console already lists it with a retry beside it. An email is the *prompt*; the
 * console is the record.
 *
 * ## Once per event, without a dedupe key
 *
 * There is no key here like `CustodyNotifier`'s, and none is needed. Three things call
 * this, and none can repeat on its own. The blocker case fires when a ceiling has
 * just come into force, and a ceiling in force is exactly what stops the failures
 * that would call it again — so the event that raises the alert cannot recur until
 * an operator clears it, which is the moment they should hear about it again. That
 * property is why the check is "is this business blocked now" rather than "did this
 * attempt cross a number": a number would miss the case where the destination
 * ceiling trips first and freezes the other count below its own. The queue case is
 * called from a job's `failed()`, which the worker runs once, when the attempt
 * budget is spent. The unconfirmed case is called from the end of a check ladder
 * that cannot restart, and says so at length on {@see self::payoutUnconfirmed()}.
 *
 * ## Sent synchronously, from inside the worker
 *
 * `Mail::send()` in a try/catch, never `Mail::queue()`, for the reason recorded at
 * length in {@see CustodyNotifier} — and that reason is now stronger rather than
 * weaker. The caller is the queue worker itself
 * ({@see SettlePayouts::failed()}), so queuing this mail would put the news
 * of a stuck payout behind whatever else is on the queue, on the very job path that
 * has just demonstrated it can stall. It goes out over SMTP from the worker, while
 * no request is waiting on anything. Never throws: the alert is not allowed to be
 * the reason a payout's bookkeeping did not finish.
 */
final class PlatformPayoutNotifier
{
    /**
     * The code used when what stopped the money was the queue spending its attempts,
     * rather than a ceiling on the business.
     *
     * A code of its own rather than the send's `retries_exhausted`, because the two
     * say different things and an operator reading the log is asking which happened:
     * that one counts refusals the provider actually returned, and this one fires
     * when the provider never answered at all.
     */
    public const SETTLEMENT_FAILED = 'settlement_failed';

    /**
     * A business's payouts have stopped and a person has to look.
     *
     * `$payout` is nullable, and that is the shape of the worst case rather than a
     * tidiness compromise. The clearest reason money stops moving is a provider we
     * cannot read: nothing is reserved, no transfer is attempted, and **there is no
     * payout row to name**. An alert that could only fire when a row existed would
     * stay silent through precisely the outage an operator most needs to hear about,
     * so the row is optional and the business is not.
     *
     * @param  array{code: string, label: string}  $blocker  why the money is standing still
     */
    public function payoutsStalled(Business $business, ?Payout $payout, array $blocker, int $failures): void
    {
        // Logged first and unconditionally. The mail is a channel that can be
        // misconfigured, and an operator without an alert address should still be
        // able to find this in the log — `Log::critical` is the level for money that
        // has stopped moving without anybody deciding it should.
        Log::critical('custody.payout.stalled', [
            'business_id' => $business->id,
            'business_code' => $business->business_code,
            'payout_id' => $payout?->id,
            'reference' => $payout?->reference,
            'blocker' => $blocker['code'],
            'failures' => $failures,
            'failure_code' => $payout?->failure_code,
            'available_kobo' => (int) $business->available_balance,
        ]);

        $recipients = $this->recipients();

        if ($recipients === []) {
            Log::error('custody.payout.stalled_no_recipient', [
                'business_id' => $business->id,
                'blocker' => $blocker['code'],
            ]);

            return;
        }

        try {
            Mail::to($recipients)->send(new AdminPayoutsStalledMail(
                business: $business,
                payout: $payout,
                blocker: $blocker,
                failures: $failures,
                heldKobo: (int) $business->available_balance,
                reviewUrl: SpaUrls::admin('/payouts'),
            ));
        } catch (\Throwable $e) {
            Log::error('custody.payout.stalled_mail_failed', [
                'business_id' => $business->id,
                'blocker' => $blocker['code'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A transfer was sent and the provider will not say whether it landed.
     *
     * ## Why this is not `payoutsStalled()`
     *
     * The two alerts sound similar and are opposites. That one reports a provider that
     * answered clearly and refused: nothing moved, every refusal was credited back, and
     * retrying is the correct next step. **This one reports a provider that has said
     * nothing at all**, and the transfer it concerns may well have landed — so every
     * reassurance that mail offers ("each refusal returned the money", "the money never
     * left their wallet") is a false statement here, and its call to action, "retry sends
     * it again immediately", is a way to pay the business twice. Reusing it would put a
     * dangerous instruction in an operator's inbox wearing the clothes of a safe one.
     *
     * So {@see AdminPayoutUnconfirmedMail} is a separate mailable saying the opposite
     * thing in as few words as possible: a transfer is out, we cannot confirm it, and
     * **check the provider's own dashboard before resending anything.**
     *
     * ## When it fires, and why one email per payout is the whole story
     *
     * From the end of {@see App\Jobs\CheckPayoutStatus}'s ladder: nine questions over
     * about two hours, and this is what happens when the last one is answered with
     * nothing. The chain cannot restart, because a ladder starts from the single method
     * that writes a row to `processing` and nothing ever puts a row back there — so no
     * dedupe key is needed for the same reason `payoutsStalled()` needs none.
     *
     * What that does **not** buy is a quiet inbox. A provider having a bad afternoon with
     * fifty transfers out is fifty of these, and that is a known limit of this alert
     * rather than an oversight: the alternative is a digest nobody has asked for, and a
     * person genuinely should hear about each one. The ladder's shape softens it — the
     * emails arrive spread across two hours, not in a burst.
     *
     * @param  int  $checks  how many times the provider was asked
     * @param  ?CarbonInterface  $since  when the transfer was sent, for how long this has been open
     */
    public function payoutUnconfirmed(
        Business $business,
        Payout $payout,
        int $checks,
        ?CarbonInterface $since = null,
    ): void {
        // Logged first and unconditionally, as the alert beside it is, and for a
        // stronger reason: this is money that has left the platform's wallet and whose
        // destination is unknown, which is the one state where a missing email is not
        // merely an inconvenience.
        Log::critical('custody.payout.unconfirmed', [
            'business_id' => $business->id,
            'business_code' => $business->business_code,
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'amount_kobo' => (int) $payout->amount_kobo,
            'currency' => $payout->currency,
            'provider' => $payout->provider,
            'provider_payout_id' => $payout->provider_payout_id,
            'provider_status' => $this->lastProviderStatus($payout),
            'checks' => $checks,
            'sent_at' => $payout->sent_at?->toIso8601String(),
        ]);

        $recipients = $this->recipients();

        if ($recipients === []) {
            Log::error('custody.payout.unconfirmed_no_recipient', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
            ]);

            return;
        }

        try {
            Mail::to($recipients)->send(new AdminPayoutUnconfirmedMail(
                business: $business,
                payout: $payout,
                checks: $checks,
                since: $since,
                reviewUrl: SpaUrls::admin('/payouts'),
            ));
        } catch (\Throwable $e) {
            Log::error('custody.payout.unconfirmed_mail_failed', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The last word the provider gave us about this transfer, if any.
     *
     * Read out of `meta.last_send`, which {@see PayoutSweepService::apply()} writes as the
     * provider's raw response body — so the shape differs per rail and both are tried:
     * Bachs answers `{"status": "processing", …}` at the top level, Paystack answers
     * `{"status": true, "data": {"status": "pending", …}}` where the outer key is a
     * success flag rather than a state.
     *
     * The `is_string` test is what makes the second shape safe to reach into: Paystack's
     * outer `status` is the boolean `true`, so a naive read would report a transfer's
     * state as `"1"`. An indeterminate send has no body at all — `last_send` is `[]` —
     * and returns null, which the mail renders as "never confirmed a status" rather than
     * as an empty cell.
     */
    private function lastProviderStatus(Payout $payout): ?string
    {
        $lastSend = $payout->meta['last_send'] ?? null;

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
     * Who hears about it: every active platform operator, then the platform address.
     *
     * ## Why every operator and not just the superadmins
     *
     * The rest of the admin mail resolves superadmins only, and this deliberately
     * does not, because the person who can act on it is whoever holds
     * `admin.finance` — and that is usually a plain `admin`. A stalled payout is
     * not a policy question for the account owner; it is a "reconnect this bank
     * and press retry" question for the finance seat, and mailing only the
     * superadmins would put the alert where the button is not.
     *
     * The obvious refinement — filter to operators who *have* the permission — was
     * considered and rejected, and the reason is timing rather than taste. Spatie's
     * teams feature is on (`config/permission.php`), so `hasPermissionTo()` answers
     * against whatever team context is currently set, and the context is set by
     * `SetPermissionsTeamId` during an admin request. This alert fires from a queue
     * worker, where no such context exists — so the check would be
     * unreliable at best and throw at worst, in the one code path that must never
     * throw. Roles need no context, so roles are what this reads.
     *
     * `status = active` because a suspended operator is not somebody to route work
     * to, and the fallback address because a superadmin list is a fact about
     * accounts that can be empty while the configured address is a fact about
     * configuration. Deduplicated: the same address is often in both, and a mail
     * addressed twice is a mail a spam filter takes more interest in.
     *
     * @return array<int, string>
     */
    private function recipients(): array
    {
        $emails = User::query()
            ->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ADMIN])
            ->where('status', 'active')
            ->pluck('email')
            ->all();

        foreach ([config('mail.admin_email'), config('mail.from.address')] as $fallback) {
            if (is_string($fallback) && $fallback !== '') {
                $emails[] = $fallback;
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($email): string => trim((string) $email), $emails),
            static fn (string $email): bool => $email !== '',
        )));
    }
}
