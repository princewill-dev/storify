<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Transaction;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * WS-5 — admin transaction status override.
 *
 * A separate one-method controller because the existing
 * Api\V1\Admin\TransactionController is shared by other workstreams and only
 * owns index/show. The route binds {transaction} by its `reference`.
 *
 * The enum-validated override is the only admin lever on money already taken:
 * dashboard/order revenue figures count CONFIRMED totals, so legacy's silent
 * update is now audit-logged (it wrote nothing to ActivityLog).
 */
class TransactionStatusController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'status' => ['required', Rule::enum(TransactionStatus::class)],
        ]);

        $oldStatus = $transaction->status instanceof TransactionStatus
            ? $transaction->status->value
            : (string) $transaction->status;

        DB::transaction(function () use ($request, $transaction, $data, $oldStatus) {
            $transaction->update(['status' => $data['status']]);

            ActivityRecorder::record(
                action: 'transaction_status_updated',
                description: "Changed transaction {$transaction->reference} status from {$oldStatus} to {$data['status']}",
                subject: $transaction,
                old: ['status' => $oldStatus],
                new: ['status' => $data['status']],
                actor: $request->user(),
            );
        });

        $transaction->refresh()->loadMissing('order:id,order_number');

        return $this->ok([
            'transaction' => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status?->value,
                'status_label' => $transaction->status?->label(),
                'order' => $transaction->order?->order_number,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
            ],
        ], 'Transaction status updated.');
    }
}
