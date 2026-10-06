<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Services\ProductFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WS-25 — Products List, Bulk Actions & Detail.
 *
 * The screens this serves: the All Products table (row selection, filters,
 * per-page, low-stock highlight, variant price range and discount display) and
 * the three bulk workflows legacy had and the new stack had lost — bulk edit
 * (price/stock/status), bulk activate/deactivate and bulk delete with image and
 * digital-file cleanup (audit gaps #1, #2, #3, #4).
 *
 * This controller owns the `GET products` URI, re-registered from the route
 * module so the list gains the filters legacy supported server-side but never
 * exposed (`from`/`to`, the 10/50/100 per-page whitelist) plus the low-stock
 * and section filters the screens need. Its row payload is a strict superset of
 * the WS-14 list payload, so every existing consumer keeps working; the
 * detail/create/edit payloads stay with ProductFormController.
 *
 * Two legacy behaviours are deliberately not carried over:
 *
 * 1. Legacy's bulk edit took `$request->input('products')` and matched ids with
 *    a bare `whereIn`, so a parallel request could tick a row between the check
 *    and the write. Every row here goes through the same per-product access
 *    check as a single edit, inside one transaction.
 * 2. Legacy's bulk paths were silent about ids the caller could not reach, and
 *    "0 product(s) updated" gave no way to tell "nothing changed" from "nothing
 *    of mine was in that list". Every response reports what was skipped.
 */
class ProductListController extends ApiController
{
    use ResolvesManagementContext;

    /** Legacy amber stock warning threshold on the products list. */
    private const LOW_STOCK_THRESHOLD = 10;

    /**
     * The product list: legacy's filters, the two it supported but never
     * surfaced, and the stock/price display fields the table columns need.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'store_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'digital_only' => ['nullable', 'boolean'],
            'has_variants' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            // Legacy accepted a created-at range server-side (no UI); the new
            // list surfaces it. `to` is inclusive of the whole day.
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(array_keys(self::sorts()))],
            // The legacy per-page whitelist (10/50/100) is what the UI offers;
            // any 1–100 page size a sibling screen already sends still works.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = $this->accessibleProductQuery($request)
            ->with(['images', 'variants', 'currency', 'store', 'warehouse', 'category', 'section'])
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['section_id'] ?? null, fn ($q, $sectionId) => $q->where('section_id', $sectionId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('digital_only'), fn ($q) => $q->where('is_digital', true))
            ->when($request->has('has_variants'), fn ($q) => $q->where('has_variants', $request->boolean('has_variants')))
            ->when($request->boolean('low_stock'), fn ($q) => $this->applyLowStockFilter($q))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Legacy searched the name, the code and the category name;
                // the brand column joined the list with WS-14.
                $q->where(fn ($inner) => $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('product_code', 'like', "%{$term}%")
                    ->orWhere('brand', 'like', "%{$term}%")
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$term}%")));
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()));

        $this->applySorting($products, $filters['sort'] ?? null);

        $page = $products->paginate($filters['per_page'] ?? 20)->withQueryString();

        return $this->ok(
            $page->getCollection()->map(fn (Product $product) => $this->rowPayload($product, $request))->values()->all(),
            null,
            200,
            $this->paginationMeta($page) + ['low_stock_threshold' => self::LOW_STOCK_THRESHOLD],
        );
    }

    /**
     * Bulk edit — legacy `bulkUpdate`. Each row carries its own optional
     * amount/quantity/stock_quantity/status; blank fields leave the product as
     * it is, exactly as the legacy modal left untouched inputs alone.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'integer'],
            'products.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'products.*.quantity' => ['nullable', 'integer', 'min:0'],
            'products.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'products.*.status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $requestedIds = collect($data['products'])->pluck('id')->map(fn ($id) => (int) $id)->unique();

        // One query, scoped to what the caller can actually reach; anything
        // else is reported back as skipped rather than silently dropped.
        $products = $this->accessibleProductQuery($request)
            ->whereIn('id', $requestedIds)
            ->get()
            ->keyBy('id');

        $skipped = $requestedIds->diff($products->keys()->map(fn ($id) => (int) $id))->values()->all();
        $updatedIds = [];
        $rejected = [];

        DB::transaction(function () use ($data, $products, &$updatedIds, &$rejected) {
            foreach ($data['products'] as $row) {
                $product = $products->get((int) $row['id']);

                if (! $product) {
                    continue;
                }

                $changes = $this->bulkChanges($row);

                if ($changes === []) {
                    continue;
                }

                try {
                    $product->update($changes);
                    $updatedIds[] = $product->id;
                } catch (ValidationException $e) {
                    // The Product model refuses some edits a store product may
                    // not hold (e.g. a zero price). Report the row instead of
                    // failing the whole batch — legacy's query-builder write
                    // skipped the guard entirely.
                    $rejected[] = ['id' => $product->id, 'message' => $this->firstMessage($e)];
                }
            }
        });

        $message = $updatedIds === [] && $rejected !== []
            ? 'No products were updated.'
            : count($updatedIds).' product(s) updated successfully.';

        return $this->ok([
            'updated' => count($updatedIds),
            'updated_ids' => $updatedIds,
            'skipped_ids' => $skipped,
            'rejected' => $rejected,
        ], $message);
    }

    /**
     * Bulk activate/deactivate — legacy `bulkStatus`.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $requestedIds = collect($data['product_ids'])->map(fn ($id) => (int) $id)->unique();

        // Read the reachable set first and report the count from it: a mass
        // update() only returns rows whose value actually changed, so an
        // already-active selection would answer "0 product(s) activated".
        $ids = $this->accessibleProductQuery($request)->whereIn('id', $requestedIds)->pluck('id');

        // The ids were read through the access scope above; the business scope
        // on the write keeps a row that changed hands in between out of reach.
        DB::transaction(fn () => Product::where('business_id', $this->user($request)->business_id)
            ->whereIn('id', $ids)
            ->update(['status' => $data['status']]));

        $label = $data['status'] === 'active' ? 'activated' : 'deactivated';

        return $this->ok([
            'updated' => $ids->count(),
            'updated_ids' => $ids->map(fn ($id) => (int) $id)->values()->all(),
            'skipped_ids' => $requestedIds->diff($ids->map(fn ($id) => (int) $id))->values()->all(),
        ], $ids->count()." product(s) {$label}.");
    }

    /**
     * Bulk delete — legacy `bulkDestroy`. Stored images and digital files go
     * with the row, and the delete runs in a transaction so a failure part-way
     * cannot leave a half-cleaned catalog.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $requestedIds = collect($data['product_ids'])->map(fn ($id) => (int) $id)->unique();

        $products = $this->accessibleProductQuery($request)
            ->with(['images', 'files'])
            ->whereIn('id', $requestedIds)
            ->get();

        DB::transaction(function () use ($products) {
            foreach ($products as $product) {
                foreach ($product->images as $image) {
                    $this->deleteImageFile($image);
                    $image->delete();
                }

                app(ProductFileService::class)->deleteAllFiles($product);

                // Stock locations carry a cascading FK, so a deleted product
                // cannot leave stock rows behind (the orphaning class of bug
                // the warehouse delete path fixed).
                $product->delete();
            }
        });

        $deletedIds = $products->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return $this->ok([
            'deleted' => count($deletedIds),
            'deleted_ids' => $deletedIds,
            'skipped_ids' => $requestedIds->diff($deletedIds)->values()->all(),
        ], count($deletedIds).' product(s) deleted.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function bulkChanges(array $row): array
    {
        $changes = [];

        foreach (['amount', 'quantity', 'stock_quantity'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $changes[$field] = $row[$field];
            }
        }

        if (isset($row['status'])) {
            $changes['status'] = $row['status'];
        }

        return $changes;
    }

    private function firstMessage(ValidationException $e): string
    {
        $errors = $e->errors();
        $messages = reset($errors);

        if (! is_array($messages)) {
            return (string) $e->getMessage();
        }

        return (string) reset($messages);
    }

    /**
     * The low_stock flag the payload publishes is computed from the variant
     * rows for variant-driven products, so the filter has to agree with it or
     * the count and the rows would disagree.
     */
    private function applyLowStockFilter($query): void
    {
        $query->where('is_digital', false)
            ->where(function ($inner) {
                $inner->where(function ($q) {
                    $q->where('has_variants', true)
                        ->whereRaw(
                            '(select coalesce(sum(quantity), 0) from product_variants where product_variants.product_id = products.id) <= ?',
                            [self::LOW_STOCK_THRESHOLD],
                        );
                })->orWhere(function ($q) {
                    $q->where('has_variants', false)->where('quantity', '<=', self::LOW_STOCK_THRESHOLD);
                });
            });
    }

    /**
     * Legacy always listed newest-first; the sort whitelist is a net-new
     * convenience for the bigger catalogs.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function sorts(): array
    {
        return [
            'newest' => ['created_at', 'desc'],
            'oldest' => ['created_at', 'asc'],
            'name' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            'price_low' => ['amount', 'asc'],
            'price_high' => ['amount', 'desc'],
            'stock_low' => ['quantity', 'asc'],
        ];
    }

    private function applySorting($query, ?string $sort): void
    {
        [$column, $direction] = self::sorts()[$sort ?? 'newest'];

        // id tiebreak keeps pagination stable when many rows share a timestamp.
        $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * Products the caller may manage: their stores, plus store-less rows the
     * business still holds in one of their warehouses (legacy created products
     * without a store, and those rows must stay editable — see the WS-14
     * controller docblock).
     */
    private function accessibleProductQuery(Request $request)
    {
        $user = $this->user($request);
        $storeIds = $this->storeIds($request);
        $warehouseIds = $user->accessibleWarehouseIds();

        return Product::query()
            ->where('business_id', $user->business_id)
            ->where(function ($query) use ($user, $storeIds, $warehouseIds) {
                $query->whereIn('store_id', $storeIds)
                    ->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereIn('warehouse_id', $warehouseIds));

                if (! $user->isRestrictedStaff()) {
                    $query->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereNull('warehouse_id'));
                }
            });
    }

    /**
     * Accessible stores minus soft-deleted ones — legacy filtered deleted
     * stores on every product read.
     *
     * @return Collection<int, int>
     */
    private function storeIds(Request $request)
    {
        return $this->user($request)
            ->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            // Restricted staff read through the staff_assignments pivot, which
            // carries its own `id`; a bare pluck is then ambiguous between the
            // two tables and the query fails. Same qualification as
            // User::accessibleStoreIds().
            ->pluck('stores.id');
    }

    private function deleteImageFile(ProductImage $image): void
    {
        try {
            Storage::disk('public')->delete($image->path);
        } catch (\Throwable $e) {
            // Non-fatal: the row goes regardless, matching legacy cleanup.
        }
    }

    /**
     * The row shape the products table renders. A superset of the WS-14 list
     * payload — store/section/source names, variant price span (original and
     * discounted), discounter, stock math, currency and the low-stock flag.
     *
     * @return array<string, mixed>
     */
    private function rowPayload(Product $product, Request $request): array
    {
        $amountKobo = $product->amount !== null ? (int) round(((float) $product->amount) * 100) : null;
        $discountPercent = $product->discount_percentage !== null ? (float) $product->discount_percentage : null;

        // Discount is applied in integer kobo and basis points — never float
        // arithmetic on the money itself.
        $discountKobo = 0;
        if ($amountKobo !== null && $discountPercent) {
            $discountKobo = (int) round($amountKobo * (int) round($discountPercent * 100) / 10000);
        }

        $displayKobo = $amountKobo !== null ? max(0, $amountKobo - $discountKobo) : null;

        $quantity = (int) $product->quantity;
        $isDigital = (bool) $product->is_digital;

        // Variant-driven products hold their stock on the variant rows; the
        // base quantity is only a fallback.
        $variantStock = $product->has_variants && $product->relationLoaded('variants')
            ? (int) $product->variants->sum('quantity')
            : null;

        $priceRange = $this->priceRange($product);

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'tags' => $product->tags,
            'amount' => $amountKobo !== null ? $amountKobo / 100 : null,
            'display_amount' => $displayKobo !== null ? $displayKobo / 100 : null,
            'discount_percentage' => $discountPercent,
            'has_discount' => $discountKobo > 0,
            'discount_amount' => $discountKobo / 100,
            'quantity' => $quantity,
            'variant_stock' => $variantStock,
            'stock_quantity' => $product->stock_quantity !== null ? (int) $product->stock_quantity : null,
            'sold_quantity' => $product->soldQuantity(),
            'stock_percentage' => $product->stockPercentage(),
            'stock_level' => $this->stockLevel($product),
            'low_stock' => ! $isDigital && ($variantStock ?? $quantity) <= self::LOW_STOCK_THRESHOLD,
            'is_digital' => $isDigital,
            'is_taxable' => (bool) $product->is_taxable,
            'status' => $product->status,
            'featured' => (bool) $product->featured,
            'cod_available' => (bool) $product->cod_available,
            'has_variants' => (bool) $product->has_variants,
            'store_id' => $product->store_id,
            'store_name' => $product->store?->name,
            'warehouse_id' => $product->warehouse_id,
            'warehouse_name' => $product->warehouse?->name,
            'section_id' => $product->section_id,
            'section_name' => $product->section?->name,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
            'currency_id' => $product->currency_id,
            'currency_code' => $product->currency?->code ?? $this->defaultCurrencyCode($request),
            'currency_symbol' => $product->currency?->symbol,
            // Legacy's Price column: a variant min–max span, or the base price
            // with the discount struck through.
            'price_range' => $priceRange,
            'display_price_range' => $this->displayPriceRange($priceRange, $discountPercent),
            'variant_count' => $product->relationLoaded('variants') ? $product->variants->count() : null,
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    /**
     * Variant price span for the list, or null when the product has no
     * variants.
     *
     * @return array{min: float, max: float}|null
     */
    private function priceRange(Product $product): ?array
    {
        if (! $product->relationLoaded('variants') || $product->variants->isEmpty()) {
            return null;
        }

        $amounts = $product->variants->pluck('amount')->map(fn ($amount) => (float) $amount);

        return ['min' => (float) $amounts->min(), 'max' => (float) $amounts->max()];
    }

    /**
     * The same span after the product-level discount, so the table can strike
     * through the original range without doing money arithmetic in the browser.
     *
     * @param  array{min: float, max: float}|null  $range
     * @return array{min: float, max: float}|null
     */
    private function displayPriceRange(?array $range, ?float $discountPercent): ?array
    {
        if ($range === null || ! $discountPercent) {
            return $range;
        }

        $discounted = fn (float $amount) => (int) round(((int) round($amount * 100)) * (int) round($discountPercent * 100) / 10000);

        return [
            'min' => ((int) round($range['min'] * 100) - $discounted($range['min'])) / 100,
            'max' => ((int) round($range['max'] * 100) - $discounted($range['max'])) / 100,
        ];
    }

    /**
     * Good/Medium/Low band for the stock bar the legacy detail screen showed.
     */
    private function stockLevel(Product $product): string
    {
        if ($product->is_digital) {
            return 'digital';
        }

        $percentage = $product->stockPercentage();

        if ($percentage >= 60) {
            return 'good';
        }

        return $percentage >= 25 ? 'medium' : 'low';
    }

    private function defaultCurrencyCode(Request $request): string
    {
        // Same fallback order as the WS-14 payload: the platform default
        // currency row, then the business's own code, then a safe constant —
        // so a product with no currency of its own reports the same code on
        // the list and the detail screen.
        return Currency::where('is_default', true)->value('code')
            ?: $this->user($request)->business?->currency
            ?: 'NGN';
    }
}
