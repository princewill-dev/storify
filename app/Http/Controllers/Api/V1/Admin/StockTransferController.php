<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransferStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\StockTransferController as ManagementStockTransferController;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * AD-14 — platform stock-transfer oversight (WS14).
 *
 * Legacy's admin transfer screen was a read-only directory plus a detail page
 * whose approve/reject/dispatch/receive actions were thin delegators to
 * `Management\StockTransferController`, while its Cancel button (wrongly)
 * posted to the management route. This workstream reproduces that shape with
 * the audit's fixes:
 *
 *  - All five transitions are admin-scoped routes that delegate to the
 *    management controller, so the stock ledger keeps its single writer and
 *    the status machine (`TransferStatus::canTransitionTo` via the model's
 *    `canBe*` guards) is enforced in exactly one place. The admin cancel is
 *    now an admin route (legacy bug #13).
 *  - The delegated call runs under a business-scoped copy of the admin's
 *    identity (see `delegate()`), so `approved_by` / `dispatched_by` /
 *    `received_by`, the stock movements' `performed_by` and the activity-log
 *    timeline all name the admin who acted — never the business owner.
 *  - The list is platform-wide with all eight `TransferStatus` cases and
 *    carries per-status counts; `q` matches the code or either location name.
 *  - The detail `actions` map is the admin audience's (state guard only);
 *    the management payload's map is keyed on management permission strings
 *    that do not gate this console.
 *
 * Legacy logged `admin_transfers_viewed` / `admin_transfer_show_viewed`.
 */
class StockTransferController extends ApiController
{
    use EnsuresPlatformAdmin;

    private const PER_PAGE = 15;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_transfers_viewed', ['user_id' => $request->user()?->id]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in($this->statusValues())],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Sort whitelisted, never taken straight from the request.
            'sort' => ['nullable', Rule::in(['created_at', 'transfer_code', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $query = StockTransfer::query()
            ->with(['fromLocation', 'toLocation', 'requester:id,name,email', 'business:id,name,business_code'])
            ->withCount('items')
            ->withSum('items as total_units', 'quantity')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Locations are polymorphic (Warehouse or Store), so the name
                // match is a per-type one rather than a plain whereHas.
                $q->where(function ($inner) use ($term) {
                    $inner->where('transfer_code', 'like', "%{$term}%")
                        ->orWhereHasMorph('fromLocation', [Warehouse::class, Store::class], fn ($location) => $location->where('name', 'like', "%{$term}%"))
                        ->orWhereHasMorph('toLocation', [Warehouse::class, Store::class], fn ($location) => $location->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id', $filters['direction'] ?? 'desc');

        $transfers = $query->paginate($filters['per_page'] ?? self::PER_PAGE)->withQueryString();

        $byStatus = StockTransfer::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return $this->ok(
            [
                'transfers' => $transfers->getCollection()->map(fn (StockTransfer $transfer) => $this->summary($transfer))->all(),
                'statuses' => collect(TransferStatus::cases())->map(fn (TransferStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->all(),
                'stats' => [
                    'total' => array_sum($byStatus),
                    'by_status' => $byStatus,
                ],
            ],
            null,
            200,
            $this->paginationMeta($transfers),
        );
    }

    public function show(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_transfer_show_viewed', ['user_id' => $request->user()?->id, 'transfer_id' => $transfer->id]);

        return $this->ok(['transfer' => $this->detail($request, $transfer)]);
    }

    public function approve(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'approve');
    }

    public function reject(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'reject');
    }

    public function dispatch(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'dispatch');
    }

    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'receive');
    }

    /**
     * The admin-scoped cancel the audit asked for (legacy posted its admin
     * Cancel button to the management route). Same single writer underneath.
     */
    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'cancel');
    }

    /**
     * Run one transition through the management controller and re-read the
     * transfer so the response carries the admin-shaped detail. Validation
     * errors and state conflicts (409) pass through untouched — the delegated
     * controller owns those messages.
     */
    private function delegateTransition(Request $request, StockTransfer $transfer, string $action): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $response = $this->delegate($request, $transfer, $action);

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        return $this->ok(
            ['transfer' => $this->detail($request, $transfer->fresh())],
            $response->getData(true)['message'] ?? null,
        );
    }

    /**
     * The transfer detail the admin screens render: the management payload
     * (items with requested/approved deltas, timeline, movements) widened
     * with the owning business and the admin audience's action flags.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, StockTransfer $transfer): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->delegate($request, $transfer, 'show')->getData(true)['data']['transfer'] ?? [];

        $transfer->loadMissing('business:id,name,business_code');

        $payload['business'] = $transfer->business ? [
            'id' => $transfer->business->id,
            'name' => $transfer->business->name,
            'business_code' => $transfer->business->business_code,
        ] : null;

        // Management gates these on `transfers …` permission strings; the
        // platform console's gate is `admin.warehouses`, so only the state
        // guards apply here.
        $payload['actions'] = $this->actions($transfer);

        return $payload;
    }

    /**
     * Delegate one controller action to the management implementation.
     *
     * The management controller resolves its tenant from the authenticated
     * user's `business_id`, and a platform admin carries none — so the
     * delegated call runs under a business-scoped copy of the admin identity:
     * the copy keeps the admin's id (every `approved_by` / `dispatched_by` /
     * `received_by` stamp, stock-movement `performed_by` and activity row
     * still names the admin who acted) and only supplies the transfer's
     * business as tenant context. The copy exists for one controller call and
     * is never persisted; the real request user is restored afterwards so the
     * route-access logger still sees the admin.
     */
    private function delegate(Request $request, StockTransfer $transfer, string $action): JsonResponse
    {
        $admin = $request->user();

        if (! $admin instanceof User) {
            abort(403, 'This endpoint is restricted to platform administrators.');
        }

        $context = clone $admin;
        $context->setAttribute('business_id', $transfer->business_id);

        $original = $request->getUserResolver();
        $request->setUserResolver(fn () => $context);

        try {
            return app(ManagementStockTransferController::class)->{$action}($request, $transfer);
        } finally {
            $request->setUserResolver($original);
        }
    }

    /**
     * What the platform console may do with this transfer right now — state
     * guard only; legacy's admin screen offered exactly these five actions.
     *
     * @return array<string, bool>
     */
    private function actions(StockTransfer $transfer): array
    {
        return [
            'approve' => $transfer->canBeApproved(),
            'reject' => $transfer->canBeRejected(),
            'dispatch' => $transfer->canBeDispatched(),
            'receive' => $transfer->canBeReceived(),
            'cancel' => $transfer->canBeCancelled(),
        ];
    }

    /**
     * Directory row.
     *
     * @return array<string, mixed>
     */
    private function summary(StockTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'transfer_code' => $transfer->transfer_code,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'from' => $this->locationRef($transfer->from_location_type, $transfer->fromLocation),
            'to' => $this->locationRef($transfer->to_location_type, $transfer->toLocation),
            'items_count' => (int) ($transfer->items_count ?? 0),
            'total_units' => (int) ($transfer->total_units ?? 0),
            'requested_by' => $transfer->requester?->name,
            'business' => $transfer->business ? [
                'id' => $transfer->business->id,
                'name' => $transfer->business->name,
                'business_code' => $transfer->business->business_code,
            ] : null,
            'created_at' => $transfer->created_at?->toISOString(),
            'updated_at' => $transfer->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationRef(string $class, ?Model $location): ?array
    {
        if (! $location) {
            return null;
        }

        $isWarehouse = $class === Warehouse::class || $location instanceof Warehouse;

        return [
            'type' => $isWarehouse ? 'warehouse' : 'store',
            'id' => $location->getKey(),
            'code' => $isWarehouse
                ? ($location instanceof Warehouse ? $location->warehouse_code : null)
                : ($location instanceof Store ? $location->store_id : null),
            'name' => $location instanceof Warehouse || $location instanceof Store ? $location->name : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function statusValues(): array
    {
        return array_map(fn (TransferStatus $status) => $status->value, TransferStatus::cases());
    }
}
