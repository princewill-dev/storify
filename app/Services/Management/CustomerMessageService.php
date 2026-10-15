<?php

namespace App\Services\Management;

use App\Mail\CustomerMessageMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * WS-56 — messages a business sends to its customers.
 *
 * One row per recipient and one queued mail per recipient, each in its own
 * transaction: a broadcast to several hundred people must not hold the request
 * open, and one customer that fails to queue must not stop the rest. The
 * recipient count is capped rather than unbounded, so a filter that accidentally
 * matches the whole book is a refusal the sender can see and narrow, not an
 * hour of mail leaving the platform.
 *
 * Consent is applied by the caller's audience query, not here (see
 * CustomerAccountRepository::audienceQuery): which customers a message *may* go
 * to is a question about them, and this service is about sending. What lives
 * here is the decision that a marketing message is the kind that asks
 * permission and a transactional one is the kind that does not.
 *
 * No open, click or bounce tracking, here or anywhere: the row records that a
 * message was composed, to whom, and when it was handed to the queue. Nothing
 * claims to know it was read.
 */
final class CustomerMessageService
{
    /**
     * The most recipients one send may queue.
     *
     * A number rather than a limit on the query: the caller is told how many
     * were left out, so "narrow the filter" is actionable instead of a silent
     * truncation.
     */
    public const MAX_RECIPIENTS = 500;

    /**
     * Send one message to one customer.
     *
     * Used by the customer's own Message action, where consent is not applied:
     * a message written about one person, from their own detail screen, is a
     * reply to something they did.
     */
    public function send(Customer $customer, User $sender, string $subject, string $body, string $category, Request $request): CustomerMessage
    {
        $message = $this->deliver($customer, $sender, $subject, $body, $category);

        ActivityLog::create([
            'user_id' => $sender->id,
            'business_id' => $customer->business_id,
            'action' => 'customer_message_sent',
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'description' => "Messaged {$customer->full_name}: {$subject}",
            'new_values' => ['message_id' => $message->id, 'category' => $category],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return $message;
    }

    /**
     * Queue one message to every customer in an already-filtered audience.
     *
     * The audience arrives as an unexecuted query because resolving it —
     * business scope, restricted-staff stores, account state and consent — is
     * query building that belongs with the rest of the read model, while the
     * cap belongs here. Taking a builder rather than a collection is what lets
     * the cap be applied in the database: the true audience size is counted,
     * the recipients are limited, and neither the caller nor this service ever
     * holds a customer book it is about to discard. It also means the two
     * numbers in the result cannot disagree, since both are derived from the
     * same query.
     *
     * @param  Builder<Customer>  $audience
     * @return array{queued: int, skipped: int, capped: int}
     */
    public function broadcast(Builder $audience, User $sender, string $subject, string $body, string $category, Request $request): array
    {
        $total = (clone $audience)->count();
        $recipients = $audience->limit(self::MAX_RECIPIENTS)->get();
        $capped = max(0, $total - $recipients->count());

        $queued = 0;
        $skipped = 0;

        foreach ($recipients as $customer) {
            try {
                $this->deliver($customer, $sender, $subject, $body, $category);
                $queued++;
            } catch (Throwable $e) {
                // One customer that cannot be queued does not stop the send.
                // The count is returned so the screen can say how many did not
                // go, rather than reporting a success that is partly untrue.
                $skipped++;

                Log::warning('api.management.customer_message_failed', [
                    'customer_id' => $customer->id,
                    'business_id' => $customer->business_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        ActivityLog::create([
            'user_id' => $sender->id,
            'business_id' => $sender->business_id,
            'action' => 'customer_message_broadcast',
            'subject_type' => Customer::class,
            'subject_id' => null,
            'description' => "Messaged {$queued} customer(s): {$subject}",
            'new_values' => ['queued' => $queued, 'skipped' => $skipped, 'capped' => $capped, 'category' => $category],
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return ['queued' => $queued, 'skipped' => $skipped, 'capped' => $capped];
    }

    /**
     * Record and queue one message.
     *
     * The order matters: the mail is queued first and the row written second,
     * both inside one transaction, so a failure between them leaves neither. If
     * the queue push fails the row is never written, which is the honest
     * outcome — nothing says a message went out when it did not. A crash after
     * the commit can still send mail for a row that is later rolled back only
     * if the queue and the database disagree, which the database queue driver
     * makes impossible here: both writes are rows in the same transaction.
     */
    private function deliver(Customer $customer, User $sender, string $subject, string $body, string $category): CustomerMessage
    {
        return DB::transaction(function () use ($customer, $sender, $subject, $body, $category) {
            $message = CustomerMessage::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'user_id' => $sender->id,
                'category' => $category,
                'subject' => $subject,
                'body' => $body,
                'status' => CustomerMessage::STATUS_QUEUED,
                'sent_at' => now(),
            ]);

            Mail::to($customer->email)->queue(new CustomerMessageMail(
                $customer,
                $message,
                (string) ($sender->business?->name ?? config('app.name')),
            ));

            return $message;
        });
    }
}
