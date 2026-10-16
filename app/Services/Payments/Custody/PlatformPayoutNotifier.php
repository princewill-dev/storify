<?php

namespace App\Services\Payments\Custody;

use App\Jobs\SettlePayouts;
use App\Mail\AdminPayoutsStalledMail;
use App\Models\Business;
use App\Models\Payout;
use App\Models\User;
use App\Support\SpaUrls;
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
 * ## Once per stall, without a dedupe key
 *
 * There is no key here like `CustodyNotifier`'s, and none is needed. Two things call
 * this, and neither can repeat on its own. The blocker case fires when a ceiling has
 * just come into force, and a ceiling in force is exactly what stops the failures
 * that would call it again — so the event that raises the alert cannot recur until
 * an operator clears it, which is the moment they should hear about it again. That
 * property is why the check is "is this business blocked now" rather than "did this
 * attempt cross a number": a number would miss the case where the destination
 * ceiling trips first and freezes the other count below its own. The queue case is
 * called from a job's `failed()`, which the worker runs once, when the attempt
 * budget is spent.
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
