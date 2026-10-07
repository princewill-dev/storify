<?php

namespace App\Services\Admin;

use App\Mail\CustomerAccountActivatedMail;
use App\Mail\CustomerAccountSuspendedMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * WS-9 (admin console) — platform customer console workflows.
 *
 * Every write pairs its mutation with the audit row inside one transaction
 * ("a rejected audit row must take the edit down with it"), then notifies
 * after the commit: legacy sent the suspension/activation e-mails inside the
 * transaction, so a slow or newly-down mail transport held the row lock; a
 * failed notification must never undo the mutation — the action is in the
 * audit trail and the mail failure is logged non-fatally, as legacy did.
 *
 * The controller keeps the HTTP shape — status codes, message strings, which
 * guard refusal maps to which 422 — and the repository owns the queries.
 */
final class CustomerModerationService
{
    /**
     * Edit details and status, with the audit row recording old and new
     * values. Legacy's status side effect is kept: an explicit Active verifies
     * the e-mail, any other explicit status clears it. A partial payload that
     * omits status leaves verification untouched (the legacy form always
     * posted the status, but nothing else in this API requires a full form).
     *
     * @param  array<string, mixed>  $data  the validated edit payload
     */
    public function update(Customer $customer, array $data, ?User $actor): void
    {
        // Payloads speak the API's lowercase spelling; the schema stores
        // uppercase. Normalising here means the fill, the status branches and
        // the audit row all read the schema spelling.
        if (isset($data['status'])) {
            $data['status'] = strtoupper($data['status']);
        }

        DB::transaction(function () use ($customer, $data, $actor) {
            $before = $this->snapshot($customer);

            $customer->fill($data);

            $status = $data['status'] ?? null;

            if ($status === Customer::STATUS_ACTIVE) {
                $customer->hasVerifiedEmail() ? $customer->save() : $customer->markEmailAsVerified();
            } elseif ($status !== null) {
                $customer->email_verified_at = null;
                $customer->save();
            } else {
                $customer->save();
            }

            $after = $this->snapshot($customer);

            if ($before !== $after) {
                ActivityRecorder::record(
                    action: 'customer_updated',
                    description: 'Customer details updated ('.implode(', ', array_keys(array_diff_assoc($after, $before))).')',
                    subject: $customer,
                    old: $before,
                    new: $after,
                    actor: $actor,
                    businessId: $customer->business_id,
                );
            }
        });
    }

    /**
     * Suspend with the required, persisted reason and the customer e-mail the
     * legacy screen sent. The already-suspended refusal runs in the controller
     * before this is called.
     */
    public function suspend(Customer $customer, string $reason, ?User $actor): void
    {
        $oldStatus = $customer->status;
        $oldVerifiedAt = $customer->email_verified_at;

        DB::transaction(function () use ($customer, $reason, $actor, $oldStatus, $oldVerifiedAt) {
            // Suspending also un-verifies the e-mail, legacy's enable/disable
            // semantics: a suspended account cannot sign back in on the
            // strength of a previously verified address.
            $customer->update([
                'status' => Customer::STATUS_SUSPENDED,
                'email_verified_at' => null,
            ]);

            ActivityRecorder::record(
                action: 'customer_suspended',
                description: "Suspended customer: {$customer->full_name}. Reason: {$reason}",
                subject: $customer,
                old: ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt?->toISOString()],
                new: ['status' => Customer::STATUS_SUSPENDED, 'email_verified_at' => null, 'reason' => $reason],
                actor: $actor,
                businessId: $customer->business_id,
            );
        });

        Log::info('api.admin.customer_suspended', [
            'customer_id' => $customer->id,
            'account_id' => $customer->account_id,
            'actor_user_id' => $actor?->id,
        ]);

        $this->notifyCustomer(
            $customer,
            fn () => new CustomerAccountSuspendedMail($customer, $reason),
            'api.admin.customer_suspension_email_failed',
        );
    }

    /**
     * Activate a suspended or deleted customer, verifying the e-mail if it is
     * not already, with legacy's notification. The already-active refusal runs
     * in the controller before this is called.
     */
    public function activate(Customer $customer, ?User $actor): void
    {
        $oldStatus = $customer->status;
        $oldVerifiedAt = $customer->email_verified_at;

        DB::transaction(function () use ($customer, $actor, $oldStatus, $oldVerifiedAt) {
            $customer->status = Customer::STATUS_ACTIVE;

            $customer->hasVerifiedEmail() ? $customer->save() : $customer->markEmailAsVerified();

            ActivityRecorder::record(
                action: 'customer_activated',
                description: "Activated customer: {$customer->full_name}",
                subject: $customer,
                old: ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt?->toISOString()],
                new: ['status' => Customer::STATUS_ACTIVE, 'email_verified_at' => $customer->email_verified_at?->toISOString()],
                actor: $actor,
                businessId: $customer->business_id,
            );
        });

        Log::info('api.admin.customer_activated', [
            'customer_id' => $customer->id,
            'account_id' => $customer->account_id,
            'actor_user_id' => $actor?->id,
        ]);

        $this->notifyCustomer(
            $customer,
            fn () => new CustomerAccountActivatedMail($customer),
            'api.admin.customer_activation_email_failed',
        );
    }

    /**
     * Scalar view of the fields the edit form owns, so the audit entry
     * compares like with like.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Customer $customer): array
    {
        return [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'location' => $customer->location,
            'status' => $customer->status,
            'email_verified_at' => $customer->email_verified_at?->toISOString(),
        ];
    }

    /**
     * A failed notification must never undo the mutation — it is queued after
     * the commit and the failure is logged, as legacy did.
     */
    private function notifyCustomer(Customer $customer, callable $mailable, string $failureLog): void
    {
        if (empty($customer->email)) {
            return;
        }

        try {
            Mail::to($customer->email)->queue($mailable());
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
