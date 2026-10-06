<?php

namespace App\Http\Resources\Management\Transfer;

use App\Enums\TransferStatus;
use App\Models\ActivityLog;
use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-15 — the transfer detail timeline.
 *
 * Ported step-for-step from the controller: each step is `done` either because
 * its `transfer.*` activity row exists or because the current status implies
 * it, and the awaiting-acknowledgment/acknowledged steps only appear once
 * quantities have been adjusted — the legacy shape. The activity rows are the
 * repository's read, keyed by action.
 *
 * @property StockTransfer $resource
 */
final class StockTransferTimelineResource extends JsonResource
{
    /**
     * @param  Collection<string, ActivityLog>  $logs
     */
    public function __construct(StockTransfer $transfer, private readonly Collection $logs)
    {
        parent::__construct($transfer);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        /** @var StockTransfer $transfer */
        $transfer = $this->resource;
        $logs = $this->logs;

        $logStep = function (string $action) use ($logs): ?array {
            $log = $logs->get($action);

            return $log ? [
                'who' => $log->user?->name,
                'at' => $log->created_at?->toISOString(),
            ] : null;
        };

        $approvedLog = $logStep('transfer.approved');
        $steps = [];

        $steps[] = [
            'key' => 'created',
            'label' => 'Created',
            'done' => true,
            'who' => $transfer->requester?->name,
            'at' => $transfer->created_at?->toISOString(),
        ];

        $submitted = $logStep('transfer.submitted');
        $submittedDone = $submitted !== null || in_array($transfer->status, [
            TransferStatus::PENDING, TransferStatus::APPROVED, TransferStatus::AWAITING_ACKNOWLEDGMENT,
            TransferStatus::DISPATCHED, TransferStatus::RECEIVED, TransferStatus::REJECTED,
        ], true);

        $steps[] = [
            'key' => 'submitted',
            'label' => 'Submitted',
            'done' => $submittedDone,
            'who' => $submitted['who'] ?? null,
            'at' => $submitted['at'] ?? null,
        ];

        $approvedDone = $approvedLog !== null
            || in_array($transfer->status, [TransferStatus::APPROVED, TransferStatus::AWAITING_ACKNOWLEDGMENT, TransferStatus::DISPATCHED, TransferStatus::RECEIVED], true);

        // Legacy did not show the (still pending) approval step on a rejected
        // or cancelled transfer.
        if ($approvedDone || ! $transfer->isRejected() && ! $transfer->isCancelled()) {
            $steps[] = [
                'key' => 'approved',
                'label' => 'Approved',
                'done' => $approvedDone,
                'who' => $approvedLog['who'] ?? ($approvedDone ? $transfer->approver?->name : null),
                'at' => $approvedLog['at'] ?? null,
            ];
        }

        // The awaiting-acknowledgment and acknowledged steps only exist once
        // quantities have been adjusted — legacy showed them the same way.
        if ($transfer->isAwaitingAcknowledgment() || $logStep('transfer.acknowledged') !== null) {
            $steps[] = [
                'key' => 'awaiting_acknowledgment',
                'label' => 'Awaiting acknowledgement',
                'done' => true,
                'who' => $approvedLog['who'] ?? null,
                'at' => $approvedLog['at'] ?? null,
            ];
        }

        $acknowledged = $logStep('transfer.acknowledged');

        if ($acknowledged !== null) {
            $steps[] = [
                'key' => 'acknowledged',
                'label' => 'Acknowledged',
                'done' => true,
                'who' => $acknowledged['who'],
                'at' => $acknowledged['at'],
            ];
        }

        $dispatched = $logStep('transfer.dispatched');
        $dispatchedDone = $dispatched !== null || $transfer->dispatcher !== null || $transfer->status === TransferStatus::RECEIVED;

        if ($dispatchedDone || in_array($transfer->status, [TransferStatus::APPROVED, TransferStatus::AWAITING_ACKNOWLEDGMENT], true) || $acknowledged !== null) {
            $steps[] = [
                'key' => 'dispatched',
                'label' => 'Dispatched',
                'done' => $dispatchedDone,
                'who' => $dispatched['who'] ?? ($dispatchedDone ? $transfer->dispatcher?->name : null),
                'at' => $dispatched['at'] ?? null,
            ];
        }

        $received = $logStep('transfer.received');
        $receivedDone = $received !== null || $transfer->receiver !== null;

        if ($receivedDone || $dispatchedDone) {
            $steps[] = [
                'key' => 'received',
                'label' => 'Received',
                'done' => $receivedDone,
                'who' => $received['who'] ?? ($receivedDone ? $transfer->receiver?->name : null),
                'at' => $received['at'] ?? null,
            ];
        }

        if ($transfer->isRejected()) {
            $rejected = $logStep('transfer.rejected');

            $steps[] = [
                'key' => 'rejected',
                'label' => 'Rejected',
                'done' => true,
                'who' => $rejected['who'] ?? $transfer->approver?->name,
                'at' => $rejected['at'] ?? $transfer->updated_at?->toISOString(),
            ];
        }

        if ($transfer->isCancelled()) {
            $cancelled = $logStep('transfer.cancelled');

            $steps[] = [
                'key' => 'cancelled',
                'label' => 'Cancelled',
                'done' => true,
                'who' => $cancelled['who'] ?? null,
                'at' => $cancelled['at'] ?? $transfer->updated_at?->toISOString(),
            ];
        }

        return $steps;
    }
}
