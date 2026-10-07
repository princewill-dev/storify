<?php

namespace App\Services\Management;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-19 — the customer edit/suspend/activate workflows.
 *
 * Each transition commits its row change (including the email-verification
 * sync) and its ActivityLog row inside one DB::transaction, so a failed audit
 * row rolls the mutation back with it. The controllers keep the HTTP shape
 * (state guards, refusal messages, envelope) and this service owns the
 * multi-table workflow and its transaction boundary.
 *
 * The actor is the real `user_id` (legacy stashed it in `metadata.user_id`).
 * A business suspend/activate writes the audit trail but does NOT e-mail the
 * customer: the only customer-facing account mailable is framed as platform
 * administration ("suspended by our administration team"), so reusing it for
 * a business-initiated action would misattribute the suspension to Storify.
 * The admin module (deferred) keeps the e-mails.
 */
final class CustomerLifecycleService
{
    /**
     * The edit form's write, including the legacy status side effect and the
     * audit row.
     *
     * Legacy side effect kept: an explicit Active verifies the email, any
     * other explicit status clears it. A partial update that omits status
     * leaves the verification untouched. The edit form does not email the
     * customer — the dedicated suspend/activate actions are the notification
     * surface.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data, Request $request): void
    {
        DB::transaction(function () use ($request, $customer, $data) {
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
                ActivityLog::create([
                    'user_id' => $this->actor($request)->id,
                    'business_id' => $customer->business_id,
                    'action' => 'customer_updated',
                    'subject_type' => Customer::class,
                    'subject_id' => $customer->id,
                    'description' => 'Customer details updated ('.implode(', ', array_keys(array_diff_assoc($after, $before))).')',
                    'old_values' => $before,
                    'new_values' => $after,
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
            }
        });
    }

    /**
     * Suspension clears the e-mail verification and stores the reason.
     */
    public function suspend(Customer $customer, string $reason, Request $request): void
    {
        DB::transaction(function () use ($request, $customer, $reason) {
            $oldStatus = $customer->status;
            $oldVerifiedAt = $customer->email_verified_at;

            $customer->update([
                'status' => Customer::STATUS_SUSPENDED,
                'email_verified_at' => null,
            ]);

            // The reason is required and stored, so the audit trail can answer
            // "why was this customer suspended?" — the legacy API validated it
            // and threw it away, and legacy business writes hid the actor in
            // metadata.user_id instead of using the column.
            ActivityLog::create([
                'user_id' => $this->actor($request)->id,
                'business_id' => $customer->business_id,
                'action' => 'customer_suspended',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Suspended customer: {$customer->full_name}. Reason: {$reason}",
                'old_values' => ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt],
                'new_values' => [
                    'status' => Customer::STATUS_SUSPENDED,
                    'email_verified_at' => null,
                    'reason' => $reason,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            Log::info('api.management.customer_suspended', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'user_id' => $this->actor($request)->id,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Activation verifies the e-mail (the legacy `hasVerifiedEmail()` branch)
     * and clears nothing else.
     */
    public function activate(Customer $customer, Request $request): void
    {
        DB::transaction(function () use ($request, $customer) {
            $oldVerifiedAt = $customer->email_verified_at;
            $oldStatus = $customer->status;

            $customer->status = Customer::STATUS_ACTIVE;

            $customer->hasVerifiedEmail() ? $customer->save() : $customer->markEmailAsVerified();

            ActivityLog::create([
                'user_id' => $this->actor($request)->id,
                'business_id' => $customer->business_id,
                'action' => 'customer_activated',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Activated customer: {$customer->full_name}",
                'old_values' => ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt],
                'new_values' => ['status' => Customer::STATUS_ACTIVE, 'email_verified_at' => $customer->email_verified_at],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            Log::info('api.management.customer_activated', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'user_id' => $this->actor($request)->id,
            ]);
        });
    }

    /**
     * Scalar view of the fields the edit form owns, so the audit entry
     * compares like with like (a Carbon and its database string are not the
     * same value to a strict comparison).
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

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
