<?php

namespace App\Http\Resources\Admin;

use App\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AD-14 — the transfer detail the admin screens render: the management
 * payload (items with requested/approved deltas, timeline, movements) widened
 * with the owning business and the admin audience's action flags.
 *
 * The management payload is produced by the delegated management controller
 * call and arrives fully shaped; this resource only appends the two
 * admin-only blocks, in the same positions the pre-refactor `detail()`
 * appended them.
 *
 * @property StockTransfer $resource
 */
final class StockTransferDetailResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $payload  the delegated management detail payload
     */
    public function __construct(StockTransfer $transfer, private readonly array $payload)
    {
        parent::__construct($transfer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockTransfer $transfer */
        $transfer = $this->resource;

        $payload = $this->payload;

        $payload['business'] = $transfer->business ? [
            'id' => $transfer->business->id,
            'name' => $transfer->business->name,
            'business_code' => $transfer->business->business_code,
        ] : null;

        // Management gates these on `transfers …` permission strings; the
        // platform console's gate is `admin.warehouses`, so only the state
        // guards apply here.
        $payload['actions'] = $this->actions();

        return $payload;
    }

    /**
     * What the platform console may do with this transfer right now — state
     * guard only; legacy's admin screen offered exactly these five actions.
     *
     * @return array<string, bool>
     */
    private function actions(): array
    {
        /** @var StockTransfer $transfer */
        $transfer = $this->resource;

        return [
            'approve' => $transfer->canBeApproved(),
            'reject' => $transfer->canBeRejected(),
            'dispatch' => $transfer->canBeDispatched(),
            'receive' => $transfer->canBeReceived(),
            'cancel' => $transfer->canBeCancelled(),
        ];
    }
}
