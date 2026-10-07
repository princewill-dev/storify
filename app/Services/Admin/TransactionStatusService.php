<?php

namespace App\Services\Admin;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;

/**
 * WS-5 — the admin transaction-status override workflow.
 *
 * The enum-validated override is the only admin lever on money already taken:
 * dashboard/order revenue figures count CONFIRMED totals, so legacy's silent
 * update is now audit-logged (it wrote nothing to ActivityLog). The write and
 * its audit row share one transaction because ActivityRecorder's contract is
 * that a failed audit row rolls the mutation back with it — the trail must
 * never silently drop an event.
 *
 * The old status is read before the write and both the audit row's old/new
 * values and its description are built from it; that capture stays here so
 * the two reads can never drift apart. Refusals and HTTP shape stay in the
 * controller: the platform-admin guard, the status code and the message.
 */
final class TransactionStatusService
{
    /**
     * Update the status and record the audit row atomically, then return the
     * transaction in its post-write state with just the order number loaded
     * for the response.
     *
     * @param  array<string, mixed>  $data  the validated payload (`status`)
     */
    public function update(Transaction $transaction, array $data, ?User $actor): Transaction
    {
        $oldStatus = $transaction->status instanceof TransactionStatus
            ? $transaction->status->value
            : (string) $transaction->status;

        DB::transaction(function () use ($transaction, $data, $oldStatus, $actor) {
            $transaction->update(['status' => $data['status']]);

            ActivityRecorder::record(
                action: 'transaction_status_updated',
                description: "Changed transaction {$transaction->reference} status from {$oldStatus} to {$data['status']}",
                subject: $transaction,
                old: ['status' => $oldStatus],
                new: ['status' => $data['status']],
                actor: $actor,
            );
        });

        return $transaction->refresh()->loadMissing('order:id,order_number');
    }
}
