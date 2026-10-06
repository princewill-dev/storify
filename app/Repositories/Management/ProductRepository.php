<?php

namespace App\Repositories\Management;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Section;
use App\Models\SizeUnit;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WeightUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-14 — query building for the product form surface.
 *
 * Scoping, filters, eager loads and the form-picker queries live here; reads
 * and query building only — transaction boundaries and abort() calls belong to
 * ProductFormService and the controller.
 *
 * The list scope carries the workstream's store-less decision (see
 * ProductFormController's class docblock): a row with `store_id = NULL` is
 * manageable while the caller can reach its warehouse, and fully detached rows
 * stay reachable for anyone who is not restricted staff.
 */
final class ProductRepository
{
    /**
     * Relations the list/summary payload renders.
     *
     * @var array<int, string>
     */
    private const LIST_RELATIONS = ['images', 'variants', 'currency', 'store', 'warehouse', 'category', 'section'];

    private ?string $defaultCurrency = null;

    /**
     * The product list: scoping, legacy filters, stock/price eager loads and
     * the inherited newest-first ordering.
     *
     * @param  array<string, mixed>  $filters  validated by ProductIndexRequest
     */
    public function paginateForUser(User $user, array $filters, bool $digitalOnly): LengthAwarePaginator
    {
        return $this->accessibleQuery($user)
            ->with(self::LIST_RELATIONS)
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($digitalOnly, fn ($q) => $q->where('is_digital', true))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Legacy searched the category name too, not just the brand.
                $q->where(fn ($inner) => $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('product_code', 'like', "%{$term}%")
                    ->orWhere('brand', 'like', "%{$term}%")
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$term}%")));
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Eager loads for the widened detail payload, verbatim from the
     * controller's detailRelations().
     *
     * @return array<int|string, mixed>
     */
    public function detailRelations(): array
    {
        return [
            'images' => fn ($query) => $query->orderBy('position'),
            'files',
            'variants' => fn ($query) => $query->orderBy('id'),
            'category',
            'store',
            'section',
            'warehouse',
            'currency',
            'sizeUnit',
            'weightUnit',
        ];
    }

    /**
     * Accessible stores minus soft-deleted ones — legacy filtered deleted
     * stores on every product read.
     *
     * @return Collection<int, int>
     */
    public function storeIds(User $user): Collection
    {
        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck('id');
    }

    /**
     * Platform default currency, then the business's own, then NGN — the
     * fallback the payload always used. Memoized per repository instance
     * (i.e. per request/action) so a list does not run it per row.
     */
    public function defaultCurrencyCode(User $user): string
    {
        return $this->defaultCurrency ??= (Currency::where('is_default', true)->value('code')
            ?: $user->business?->currency
            ?: 'NGN');
    }

    /**
     * The picker data the form-options endpoint renders.
     *
     * @return array<string, mixed>
     */
    public function formOptions(User $user): array
    {
        return [
            'stores' => $user->accessibleStores()
                ->where('status', '!=', Store::STATUS_DELETED)
                ->orderBy('name')
                ->get(),
            'warehouses' => $user->accessibleWarehouses()
                ->where('status', '!=', Warehouse::STATUS_DELETED)
                ->with(['sections' => fn ($query) => $query->where('status', '!=', Section::STATUS_DELETED)->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'categories' => Category::where('business_id', $user->business_id)
                ->whereIn('store_id', $this->storeIds($user))
                ->orderBy('name')
                ->get(),
            'currencies' => Currency::orderBy('code')->get(),
            'size_units' => SizeUnit::orderBy('name')->get(),
            'weight_units' => WeightUnit::orderBy('name')->get(),
            'business_currency' => $this->defaultCurrencyCode($user),
        ];
    }

    /**
     * Products the caller may manage: their stores, plus store-less rows the
     * business still holds in one of their warehouses, plus fully detached
     * rows for anyone who is not restricted staff.
     */
    private function accessibleQuery(User $user): Builder
    {
        $storeIds = $this->storeIds($user);
        $warehouseIds = $user->accessibleWarehouseIds();

        return Product::query()
            ->where('business_id', $user->business_id)
            ->where(function ($query) use ($user, $storeIds, $warehouseIds) {
                $query->whereIn('store_id', $storeIds)
                    ->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereIn('warehouse_id', $warehouseIds));

                // Fully detached digital rows (legacy allowed a product with no
                // store and no warehouse) stay reachable for the business.
                if (! $user->isRestrictedStaff()) {
                    $query->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereNull('warehouse_id'));
                }
            });
    }
}
