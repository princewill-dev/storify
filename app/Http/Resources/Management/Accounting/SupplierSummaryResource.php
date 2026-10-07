<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the WS-22 suppliers list, and the contact card the create/edit/
 * detail payloads return.
 *
 * `bills_count`, `bills_total_kobo` and `bills_paid_kobo` are the list query's
 * aggregates (App\Repositories\Accounting\SupplierRepository); when the model
 * carries the loaded `bills` relation instead (the detail screen), the count
 * falls back to it.
 */
final class SupplierSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Supplier $supplier */
        $supplier = $this->resource;

        $total = (int) ($supplier->bills_total_kobo ?? 0);
        $paid = (int) ($supplier->bills_paid_kobo ?? 0);

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'notes' => $supplier->notes,
            'is_active' => (bool) $supplier->is_active,
            'bills_count' => (int) ($supplier->bills_count ?? $supplier->bills->count()),
            'bills_total_kobo' => $total,
            'bills_paid_kobo' => $paid,
            // Legacy definition of Outstanding: non-void totals minus what has
            // been paid on them, floored at zero.
            'outstanding_kobo' => max(0, $total - $paid),
        ];
    }
}
