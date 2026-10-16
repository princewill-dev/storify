<?php

namespace App\Services\Payments\Custody;

use App\Mail\PayoutBlockedMail;
use App\Mail\PayoutFailedMail;
use App\Mail\PayoutPaidMail;
use App\Mail\PayoutReversedMail;
use App\Mail\WalletCreditedMail;
use App\Models\Business;
use App\Models\BusinessNotification;
use App\Models\ConnectWebhookEvent;
use App\Models\Payout;
use App\Models\StoreBank;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Telling a business about its money, once, by two channels.
 *
 * Every event in collect-and-pay-out that a business would want to know about was
 * previously either unannounced — nothing mailed a payout, at all — or announced
 * inconsistently: the one payment mail path that existed never fired on the
 * automated settlement route, which is the route nearly every sale takes. So a
 * business's only notification that it had been paid was its bank statement.
 *
 * ## Two channels, deliberately independent
 *
 * A `business_notifications` row is the in-app record; an email is the push. They
 * are written in that order and the first is not undone by the second failing. A
 * deployment with no guaranteed queue worker and an SMTP server that can refuse
 * mid-sweep is not a hypothetical, and the failure mode to design out is the one
 * where a mail fault swallows the notification entirely.
 *
 * ## The row is the exactly-once guard
 *
 * Every `notify*()` method is a no-op the second time it is called for the same
 * event, because the insert is `insertOrIgnore` against a unique `dedupe_key` and
 * the affected-row count decides who got there first. That matters because the
 * events themselves are *already* claimed exactly once upstream — `settle()`,
 * `fail()` and `undo()` each claim by condition, and the wallet credit in
 * `recordCollectedSale()` claims on `settle_at` — so a webhook replay or a
 * `reconcile()` re-resolve reaches the hook only when it also reached the claim.
 * The key is the second lock on the same door, and it is the one that holds if the
 * claim's conditions ever widen.
 *
 * ## Why the mail is sent synchronously
 *
 * `Mail::send()` in a try/catch, never `Mail::queue()`. This deployment's queue is
 * the database one and has no guaranteed worker, and a money alert that might sit
 * in a table forever is worse than a slow one: a business that is told late is
 * inconvenienced, a business that is never told asks its bank. The cost is that a
 * slow SMTP server slows the send, which is bounded by the mail transport's own
 * timeout rather than by a queue.
 */
final class CustodyNotifier
{
    /**
     * A collected sale's share landed in the wallet.
     *
     * Written from {@see CustodyService::recordCollectedSale()} after the credit
     * and after the `settle_at` claim, so the figures on it are the wallet's state
     * once the money is in it.
     */
    public function walletCredited(Transaction $transaction, int $netKobo): ?BusinessNotification
    {
        $business = $transaction->business;

        if (! $business instanceof Business) {
            return null;
        }

        $currency = (string) ($transaction->currency ?: config('custody.currency', 'NGN'));
        $amount = $this->money($netKobo, $currency);

        return $this->notify(
            business: $business,
            type: BusinessNotification::TYPE_WALLET_CREDITED,
            // Keyed on the transaction, not the amount or the day: two sales of the
            // same size a minute apart are two events, and one sale credited twice
            // is one.
            dedupeKey: 'custody.credit:txn:'.$transaction->id,
            level: BusinessNotification::LEVEL_SUCCESS,
            title: 'Payment received — '.$amount,
            body: sprintf(
                '%s was added to your wallet%s. It is held while the payment finishes clearing, '
                .'then it becomes payable and is sent to your bank automatically.',
                $amount,
                $this->storeSuffix($transaction),
            ),
            subject: $transaction,
            amountKobo: $netKobo,
            currency: $currency,
            metadata: [
                // The sale's own reference, so the alert can be opened. The row
                // carries the transaction's id as `subject_id`, and the business's
                // screens address a sale by its reference — an id the SPA has no
                // route for is an alert that can be read and not followed.
                'reference' => (string) $transaction->reference,
                'available_kobo' => (int) $business->available_balance,
                'pending_kobo' => (int) $business->pending_balance,
                'balance_kobo' => $business->walletBalance(),
                'settle_at' => $transaction->settle_at?->toIso8601String(),
            ],
            mail: fn (User $user) => new WalletCreditedMail(
                user: $user,
                business: $business,
                amountKobo: $netKobo,
                currency: $currency,
                pendingKobo: (int) $business->pending_balance,
                availableKobo: (int) $business->available_balance,
                storeName: $this->storeName($transaction),
                reference: (string) $transaction->reference,
            ),
        );
    }

    /**
     * A payout reached the business's bank.
     *
     * The one hook whose figures have to be read deliberately: the wallet was
     * debited when the payout was *reserved*, so "what is left" is the balance as
     * it stands now rather than anything the payout changed. Saying so is the
     * difference between a mail that explains the wallet and one that contradicts
     * it.
     */
    public function payoutPaid(Payout $payout): ?BusinessNotification
    {
        $business = $payout->business;

        if (! $business instanceof Business) {
            return null;
        }

        $currency = (string) ($payout->currency ?: config('custody.currency', 'NGN'));
        $amount = $this->money((int) $payout->amount_kobo, $currency);
        $bankName = $this->bankNameFor($payout, $business);

        return $this->notify(
            business: $business,
            type: BusinessNotification::TYPE_PAYOUT_PAID,
            // Keyed on the reference, which is unique per payout and pinned on the
            // row before anything is sent — so a resend with the same reference is
            // the same event, and a second payout is never.
            dedupeKey: 'payout.paid:po:'.$payout->reference,
            level: BusinessNotification::LEVEL_SUCCESS,
            title: 'Payout sent — '.$amount,
            body: sprintf(
                '%s was sent to %s ending %s. It reaches your bank account according to your bank\'s own '
                .'timeline%s.',
                $amount,
                $bankName ?? 'your bank account',
                $this->lastFour((string) $payout->account_number),
                $payout->paid_at !== null ? ' (sent '.$payout->paid_at->toDayDateTimeString().')' : '',
            ),
            subject: $payout,
            amountKobo: (int) $payout->amount_kobo,
            currency: $currency,
            metadata: [
                'reference' => $payout->reference,
                'provider_payout_id' => $payout->provider_payout_id,
                'bank_code' => $payout->bank_code,
                'account_number' => $payout->account_number,
                'paid_at' => $payout->paid_at?->toIso8601String(),
                'wallet_remaining_kobo' => $business->walletBalance(),
            ],
            mail: fn (User $user) => new PayoutPaidMail(
                user: $user,
                business: $business,
                payout: $payout,
                walletRemainingKobo: $business->walletBalance(),
                bankName: $bankName,
            ),
        );
    }

    /**
     * A payout was refused and the money is back in the wallet.
     *
     * The `:attempt` on the key is the one key here that is not about identity.
     * Events keyed on the payout's reference are single, but a failure is not: a
     * business is refused, fixes nothing, and is refused again on the next attempt,
     * and those are two things it needs to be told. The attempt number is how many
     * failures this destination has already recorded, so the key changes when the
     * *reason to write* changes and not before.
     */
    public function payoutFailed(Payout $payout, ?string $reason, int $attempt): ?BusinessNotification
    {
        $business = $payout->business;

        if (! $business instanceof Business) {
            return null;
        }

        $currency = (string) ($payout->currency ?: config('custody.currency', 'NGN'));
        $amount = $this->money((int) $payout->amount_kobo, $currency);
        $bankName = $this->bankNameFor($payout, $business);

        return $this->notify(
            business: $business,
            type: BusinessNotification::TYPE_PAYOUT_FAILED,
            dedupeKey: 'payout.failed:po:'.$payout->reference.':'.$attempt,
            level: BusinessNotification::LEVEL_WARNING,
            title: 'Payout could not be sent — '.$amount,
            body: sprintf(
                'We tried to send %s to %s ending %s and it was refused. Nothing was lost: the money is back in '
                .'your wallet and we will try again automatically.%s',
                $amount,
                $bankName ?? 'your bank account',
                $this->lastFour((string) $payout->account_number),
                $reason !== null && $reason !== '' ? ' Reason: '.$reason : '',
            ),
            subject: $payout,
            amountKobo: (int) $payout->amount_kobo,
            currency: $currency,
            metadata: [
                'reference' => $payout->reference,
                'failure_code' => $payout->failure_code,
                'failure_reason' => $payout->failure_reason,
                'attempt' => $attempt,
                'bank_code' => $payout->bank_code,
                'wallet_balance_kobo' => $business->walletBalance(),
            ],
            mail: fn (User $user) => new PayoutFailedMail(
                user: $user,
                business: $business,
                payout: $payout,
                walletBalanceKobo: $business->walletBalance(),
                bankName: $bankName,
            ),
        );
    }

    /**
     * A payout that had landed was returned to Storify.
     *
     * Keyed on the reference with no attempt suffix, because unlike a failure this
     * happens once: the provider told us the money arrived and then told us it came
     * back, and there is no second return of the same transfer.
     */
    public function payoutReversed(Payout $payout, ?string $reason): ?BusinessNotification
    {
        $business = $payout->business;

        if (! $business instanceof Business) {
            return null;
        }

        $currency = (string) ($payout->currency ?: config('custody.currency', 'NGN'));
        $amount = $this->money((int) $payout->amount_kobo, $currency);
        $bankName = $this->bankNameFor($payout, $business);

        return $this->notify(
            business: $business,
            type: BusinessNotification::TYPE_PAYOUT_REVERSED,
            dedupeKey: 'payout.reversed:po:'.$payout->reference,
            level: BusinessNotification::LEVEL_CRITICAL,
            title: 'Payout returned — '.$amount,
            body: sprintf(
                'A transfer of %s to %s was sent back after it had landed. The money is in your wallet '
                .'again and we will send it again automatically.%s',
                $amount,
                $bankName ?? 'your bank account',
                $reason !== null && $reason !== '' ? ' Reason: '.$reason : '',
            ),
            subject: $payout,
            amountKobo: (int) $payout->amount_kobo,
            currency: $currency,
            metadata: [
                'reference' => $payout->reference,
                'reason' => $payout->failure_reason,
                'account_number' => $payout->account_number,
                'bank_code' => $payout->bank_code,
                'wallet_balance_kobo' => $business->walletBalance(),
            ],
            mail: fn (User $user) => new PayoutReversedMail(
                user: $user,
                business: $business,
                payout: $payout,
                walletBalanceKobo: $business->walletBalance(),
                bankName: $bankName,
            ),
        );
    }

    /**
     * The provider has refused this payout bank too many times, so we have stopped
     * trying.
     *
     * Raised from the failure that crosses the ceiling rather than from the send,
     * and that placement is the whole reason it is tellable: the send would
     * re-fire this on every attempt, while the crossing is a single moment. Keyed on
     * the destination rather than the payout, because the
     * thing that is blocked is the bank account and the payout that happened to
     * cross the line is not the subject.
     */
    public function destinationBlocked(Business $business, Payout $payout, int $attempts): ?BusinessNotification
    {
        $bank = $business->payoutBank;

        $currency = (string) ($payout->currency ?: config('custody.currency', 'NGN'));

        return $this->notify(
            business: $business,
            type: BusinessNotification::TYPE_PAYOUT_BLOCKED,
            dedupeKey: 'payout.blocked:dest:'.$business->id.':'.$payout->bank_code.':'.$payout->account_number,
            level: BusinessNotification::LEVEL_CRITICAL,
            title: 'Action needed — we cannot send your payouts',
            body: sprintf(
                'We have tried to send money to %s ending %s %d times and your bank has refused it each time, '
                .'so we have stopped trying. %s is still in your wallet and cannot be sent until the '
                .'account is corrected.%s',
                $bank?->bank_name ?? 'your payout account',
                $this->lastFour((string) $payout->account_number),
                $attempts,
                $this->money($business->walletBalance(), $currency),
                $payout->failure_reason !== null && $payout->failure_reason !== ''
                    ? ' Reason: '.$payout->failure_reason
                    : '',
            ),
            subject: $business,
            amountKobo: $business->walletBalance(),
            currency: $currency,
            metadata: [
                'bank_code' => $payout->bank_code,
                'account_number' => $payout->account_number,
                'attempts' => $attempts,
                'wallet_balance_kobo' => $business->walletBalance(),
            ],
            mail: fn (User $user) => new PayoutBlockedMail(
                user: $user,
                business: $business,
                walletBalanceKobo: $business->walletBalance(),
                bankName: $bank?->bank_name,
                bankCode: $payout->bank_code,
                accountNumber: $payout->account_number,
                accountName: $payout->account_name,
                attempts: $attempts,
                lastReason: $payout->failure_reason,
                currency: $currency,
            ),
        );
    }

    /**
     * Write the record, and send the mail if this call is the one that wrote it.
     *
     * The insert comes first and is the claim. Everything after it is best-effort,
     * because the two channels are independent: a business that can read the
     * notification in the app has been told, whether or not the mail left.
     *
     * `insertOrIgnore` rather than an existence check, for the same reason
     * {@see ConnectWebhookEvent::claim()} uses it: a read followed by a
     * write is the shape that races, and a webhook replay arriving beside a
     * `reconcile()` re-resolve is ordinary rather than exceptional.
     *
     * @param  callable(User): Mailable  $mail
     */
    private function notify(
        Business $business,
        string $type,
        string $dedupeKey,
        string $level,
        string $title,
        string $body,
        Model $subject,
        ?int $amountKobo,
        ?string $currency,
        array $metadata,
        callable $mail,
    ): ?BusinessNotification {
        $owner = $business->owner;

        $now = now();

        $inserted = BusinessNotification::query()->insertOrIgnore([
            'business_id' => $business->id,
            'user_id' => $owner?->id,
            'type' => $type,
            'level' => $level,
            'title' => Str::limit($title, 191, ''),
            'body' => $body,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'amount_kobo' => $amountKobo,
            'currency' => $currency,
            'metadata' => json_encode($metadata),
            'dedupe_key' => $dedupeKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Already told. Not an error and not worth a log line at this level: the
        // second delivery of the same webhook is the normal case.
        if ($inserted === 0) {
            return null;
        }

        $record = BusinessNotification::query()->where('dedupe_key', $dedupeKey)->first();

        if ($record === null) {
            return null;
        }

        $this->deliver($record, $owner, $mail);

        return $record;
    }

    /**
     * Send the mail, and record either that it went or why it did not.
     *
     * Never throws. A mail failure is a missing channel, not a missing
     * notification, and letting it propagate would make the alerting the reason a
     * payout's bookkeeping did not finish — which is the wrong way round for every
     * caller this has.
     */
    private function deliver(BusinessNotification $record, ?User $owner, callable $mail): void
    {
        if ($owner === null || blank($owner->email)) {
            // No one to mail. The record stands on its own as the notification,
            // and `emailed_at` staying null with no `mail_error` is the true and
            // distinguishable statement that there was no recipient.
            Log::info('custody.notify.no_recipient', [
                'notification_id' => $record->id,
                'business_id' => $record->business_id,
                'type' => $record->type,
            ]);

            return;
        }

        try {
            Mail::to($owner->email, (string) $owner->name)->send($mail($owner));

            $record->forceFill(['emailed_at' => now(), 'mail_error' => null])->save();
        } catch (\Throwable $e) {
            // The row is deliberately left in place. Dropping it because the mail
            // failed would turn a mail outage into a notification outage.
            $record->forceFill(['mail_error' => Str::limit($e->getMessage(), 500, '')])->save();

            Log::error('custody.notify.mail_failed', [
                'notification_id' => $record->id,
                'business_id' => $record->business_id,
                'type' => $record->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function money(int $kobo, string $currency): string
    {
        return $currency.' '.number_format($kobo / 100, 2);
    }

    /**
     * The bank's name, or null when the row and the business no longer agree.
     *
     * `payouts` snapshots a bank code and an account number, not a bank name, so
     * the name is only recoverable by matching the two against what the business
     * has on file. When they do not match — the account was changed between the
     * attempt and this mail — null is the honest answer, and the mailable falls
     * back to the digits. Guessing a name for a bank code we no longer hold would
     * be worse than saying less.
     */
    private function bankNameFor(Payout $payout, Business $business): ?string
    {
        $bank = $business->payoutBank;

        if (! $bank instanceof StoreBank) {
            return null;
        }

        $same = (string) $bank->bank_code === (string) $payout->bank_code
            && (string) $bank->account_number === (string) $payout->account_number;

        return $same ? $bank->bank_name : null;
    }

    private function lastFour(string $accountNumber): string
    {
        return strlen($accountNumber) <= 4 ? $accountNumber : substr($accountNumber, -4);
    }

    private function storeName(Transaction $transaction): ?string
    {
        $store = $transaction->order?->store ?? $transaction->invoice?->store;

        return $store?->name;
    }

    private function storeSuffix(Transaction $transaction): string
    {
        $name = $this->storeName($transaction);

        return $name !== null ? ' from your order at '.$name : '';
    }
}
