<?php

namespace App\Services\Transactions;

use App\Mail\PaymentConfirmedMail;
use App\Mail\PaymentRejectedMail;
use App\Mail\RefundProcessedMail;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Management\TransactionRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * WS-18 — queues the legacy payment mails.
 *
 * Only order-linked transactions have a mail to send: the legacy confirm/reject
 * blocks read `$transaction->order` unguarded, so every invoice payment died
 * inside their own catch and notified nobody — that is preserved deliberately,
 * not by accident.
 */
class PaymentMailDispatcher
{
    public function __construct(
        private readonly TransactionRepository $transactions,
    ) {}

    public function queue(Transaction $transaction, string $kind, ?string $reason = null): void
    {
        $order = $transaction->order;
        $store = $this->transactions->storeFor($transaction);

        if (! $order || ! $store) {
            return;
        }

        $customer = $order->customer;
        $owner = $store->business?->owner ?? $store->user;

        try {
            if ($kind === 'refunded') {
                // Legacy refunded the customer only; RefundProcessedMail is
                // typed to a Customer for exactly that reason.
                if ($customer?->email) {
                    Mail::to($customer->email)->queue(
                        new RefundProcessedMail($transaction, $order, $customer, $store, (string) $reason)
                    );
                }

                Log::info('api.management.refund_mail_queued', [
                    'transaction_id' => $transaction->id,
                    'customer_email' => $customer->email ?? null,
                ]);

                return;
            }

            $recipients = [];

            if ($customer?->email) {
                $recipients[$customer->email] = $customer;
            }

            if ($owner?->email && ! isset($recipients[$owner->email])) {
                $recipients[$owner->email] = $owner;
            }

            foreach ($this->adminEmails() as $email) {
                $recipients[$email] = $recipients[$email] ?? null;
            }

            foreach ($recipients as $email => $recipient) {
                $mail = $kind === 'rejected'
                    ? new PaymentRejectedMail($transaction, $order, $recipient, $store, $reason)
                    : new PaymentConfirmedMail($transaction, $order, $recipient, $store);

                Mail::to($email)->queue($mail);
            }

            Log::info('api.management.payment_mail_queued', [
                'transaction_id' => $transaction->id,
                'kind' => $kind,
                'recipients' => array_keys($recipients),
            ]);
        } catch (\Throwable $e) {
            // A mail failure must never roll back the money movement.
            Log::error('api.management.payment_mail_failed', [
                'transaction_id' => $transaction->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Platform admins, mirroring the KYC notification recipients.
     *
     * @return array<int, string>
     */
    private function adminEmails(): array
    {
        $emails = User::query()
            ->where('role', User::ROLE_SUPERADMIN)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($emails === [] && config('mail.admin_email')) {
            $emails = [config('mail.admin_email')];
        }

        return $emails;
    }
}
