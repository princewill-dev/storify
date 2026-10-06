<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WarehouseController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->accessibleQuery($request)
            ->withCount(['sections', 'assignedStaff'])
            ->withSum('stockLocations as total_stock', 'quantity')
            ->withCount(['stockLocations as product_count'])
            ->withCount(['stockLocations as low_stock_count' => fn ($q) => $q->lowStock()])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name');

        $warehouses = $query->paginate($filters['per_page'] ?? 20)->withQueryString();

        return $this->ok(
            ['warehouses' => $warehouses->getCollection()->map(fn (Warehouse $w) => $this->summary($w))->all()],
            null,
            200,
            $this->paginationMeta($warehouses),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->validated($request);

        $warehouse = DB::transaction(function () use ($user, $data) {
            $warehouse = Warehouse::create([
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ($data['is_active'] ?? true) ? Warehouse::STATUS_ACTIVE : Warehouse::STATUS_INACTIVE,
            ]);

            $this->syncStaff($warehouse, $data['staff_ids'] ?? []);

            return $warehouse;
        });

        Log::info('api.management.warehouse_created', [
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
        ]);

        return $this->ok(['warehouse' => $this->detail($warehouse)], 'Warehouse created.', 201);
    }

    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $warehouse->load(['stockLocations.product', 'sections', 'assignedStaff']);

        $movements = StockMovement::whereIn('stock_location_id', $warehouse->stockLocations->pluck('id'))
            ->with(['product', 'performedBy'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'movement_code' => $m->movement_code,
                'type' => $m->type?->value ?? $m->type,
                'quantity' => (int) $m->quantity,
                'balance_after' => (int) $m->balance_after,
                'product' => $m->product?->name,
                'performed_by' => $m->performedBy?->name,
                'created_at' => $m->created_at?->toISOString(),
            ])->all();

        return $this->ok([
            'warehouse' => $this->detail($warehouse),
            'stats' => [
                'total_stock' => (int) $warehouse->stockLocations->sum('quantity'),
                // The legacy grid was Product-driven while its count was
                // StockLocation-driven, so the two could disagree. Both read
                // from the same collection here.
                'product_count' => $warehouse->stockLocations->where('quantity', '>', 0)->count(),
                'low_stock_count' => $warehouse->stockLocations->filter->isLowStock()->count(),
                'sections_count' => $warehouse->sections->count(),
                'staff_count' => $warehouse->assignedStaff->count(),
            ],
            'recent_movements' => $movements,
        ]);
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $data = $this->validated($request);

        DB::transaction(function () use ($warehouse, $data) {
            $warehouse->update([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ($data['is_active'] ?? true) ? Warehouse::STATUS_ACTIVE : Warehouse::STATUS_INACTIVE,
            ]);

            $this->syncStaff($warehouse, $data['staff_ids'] ?? []);
        });

        return $this->ok(['warehouse' => $this->detail($warehouse->fresh())], 'Warehouse updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        if ($warehouse->stockLocations()->where('quantity', '>', 0)->exists()) {
            return $this->error('Move the remaining stock out of this warehouse before deleting it.', 422);
        }

        $warehouse->update(['status' => Warehouse::STATUS_DELETED]);

        Log::info('api.management.warehouse_deleted', [
            'user_id' => $this->user($request)->id,
            'warehouse_id' => $warehouse->id,
        ]);

        return $this->ok([], 'Warehouse deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'exists:users,id'],
        ]);
    }

    /**
     * The legacy multi-select kept only the last id it was handed, so a
     * warehouse could never end up with more than one assignee.
     *
     * @param  array<int, int>  $staffIds
     */
    private function syncStaff(Warehouse $warehouse, array $staffIds): void
    {
        $warehouse->assignedStaff()->sync(array_values(array_filter($staffIds)));
    }

    private function accessibleQuery(Request $request)
    {
        $query = $this->user($request)->accessibleWarehouses();

        return $query->where(
            $query->getModel()->qualifyColumn('status'),
            '!=',
            Warehouse::STATUS_DELETED,
        );
    }

    private function authorizeWarehouse(Request $request, Warehouse $warehouse): void
    {
        $user = $this->user($request);

        $allowed = $this->accessibleQuery($request)
            ->whereKey($warehouse->getKey())
            ->exists();

        if (! $allowed) {
            abort(403, 'You do not have access to this warehouse.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'warehouse_code' => $warehouse->warehouse_code,
            'name' => $warehouse->name,
            'city' => $warehouse->city,
            'state' => $warehouse->state,
            'status' => $warehouse->status->value,
            'sections_count' => (int) ($warehouse->sections_count ?? 0),
            'staff_count' => (int) ($warehouse->assigned_staff_count ?? 0),
            'product_count' => (int) ($warehouse->product_count ?? 0),
            'total_stock' => (int) ($warehouse->total_stock ?? 0),
            'low_stock_count' => (int) ($warehouse->low_stock_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Warehouse $warehouse): array
    {
        $warehouse->loadMissing(['sections', 'assignedStaff']);

        return [
            ...$this->summary($warehouse),
            'address' => $warehouse->address,
            'country' => $warehouse->country,
            'contact_person' => $warehouse->contact_person,
            'contact_phone' => $warehouse->contact_phone,
            'description' => $warehouse->description,
            'sections' => $warehouse->sections->map(fn ($section) => [
                'id' => $section->id,
                'section_code' => $section->section_code,
                'name' => $section->name,
                'status' => $section->status->value,
            ])->all(),
            'staff' => $warehouse->assignedStaff->map(fn (User $staff) => [
                'id' => $staff->id,
                'account_code' => $staff->account_code,
                'name' => $staff->name,
                'email' => $staff->email,
            ])->all(),
        ];
    }
}
