<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\ActivityLog;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-15 — the transfer detail payload: summary fields widened with notes,
 * actors, per-line items, the timeline, the ledger movements and the action
 * flags.
 *
 * The relations arrive eager-loaded by the repository; `available_at_source`
 * is precomputed there (it reads the same two sources dispatch reads) and
 * passed in keyed by item id, so shaping issues no queries of its own beyond
 * the loaded relations.
 *
 * @property StockTransfer $resource
 */
final class StockTransferDetailResource extends JsonResource
{
    /**
     * @param  Collection<string, ActivityLog>  $activityLogs
     * @param  Collection<int, StockMovement>  $movements
     * @param  array<int, int>  $availableAtSource  keyed by StockTransferItem id
     */
    public function __construct(
        StockTransfer $transfer,
        private readonly User $user,
        private readonly Collection $activityLogs,
        private readonly Collection $movements,
        private readonly array $availableAtSource,
    ) {
        parent::__construct($transfer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockTransfer $transfer */
        $transfer = $this->resource;

        return [
            ...(new StockTransferSummaryResource($transfer))->toArray($request),
            'notes' => $transfer->notes,
            'rejection_reason' => $transfer->rejection_reason,
            'approved_by' => $transfer->approver?->name,
            'dispatched_by' => $transfer->dispatcher?->name,
            'received_by' => $transfer->receiver?->name,
            'items' => $transfer->items->map(function (StockTransferItem $item) {
                $approved = $item->approved_quantity;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product' => [
                        'id' => $item->product?->id,
                        'name' => $item->product?->name ?? 'Unknown product',
                        'product_code' => $item->product?->product_code,
                        'image_url' => $item->product?->primaryImage()?->path
                            ? asset('storage/'.$item->product->primaryImage()->path)
                            : null,
                    ],
                    'variant_label' => $item->variant?->variant_code,
                    'quantity' => (int) $item->quantity,
                    'approved_quantity' => $approved !== null ? (int) $approved : null,
                    'adjusted' => $approved !== null && (int) $approved < (int) $item->quantity,
                    'available_at_source' => $this->availableAtSource[$item->id] ?? 0,
                ];
            })->values()->all(),
            'timeline' => (new StockTransferTimelineResource($transfer, $this->activityLogs))->toArray($request),
            'movements' => $this->movements
                ->map(fn (StockMovement $movement) => (new TransferMovementResource($movement))->toArray($request))
                ->all(),
            'actions' => $this->actions(),
        ];
    }

    /**
     * What the signed-in user may do with this transfer right now — state
     * guard and permission together, so the SPA never renders a button the
     * API would refuse.
     *
     * @return array<string, bool>
     */
    private function actions(): array
    {
        /** @var StockTransfer $transfer */
        $transfer = $this->resource;
        $user = $this->user;

        return [
            'approve' => $transfer->canBeApproved() && $user->can('transfers approve'),
            'reject' => $transfer->canBeRejected() && $user->can('transfers approve'),
            // Legacy gated submit, cancel and acknowledge on `transfers create`.
            'submit' => $transfer->canBeSubmitted() && $user->can('transfers create'),
            'acknowledge' => $transfer->canBeAcknowledged() && $user->can('transfers create'),
            'dispatch' => $transfer->canBeDispatched() && $user->can('transfers dispatch'),
            'receive' => $transfer->canBeReceived() && $user->can('transfers receive'),
            'cancel' => $transfer->canBeCancelled() && $user->can('transfers create'),
        ];
    }
}
