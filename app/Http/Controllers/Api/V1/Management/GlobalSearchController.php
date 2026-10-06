<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * WS-34 — global search for the Ctrl/⌘K palette.
 *
 * Registered by routes/api/v1/management/ws34-search-badges.php on the same
 * method+URI as the thin base SearchController and WS-19's
 * CustomerSearchController; modules load in filename order, so this is the
 * winning definition (WS-19's comment anticipates exactly that). It absorbs
 * WS-19's customer improvements (account_id + full-name CONCAT matching, the
 * `customers view` gate, restricted-staff order scoping) rather than dropping
 * them.
 *
 * Legacy's six groups — Products, Stores, Warehouses, Customers,
 * Transactions, Staff — all come back, plus the Orders group the SPA already
 * searched for (removing it would have been a regression). Every group is
 * capped at five rows, gated on the caller's permission for that module, and
 * returns ready-made title/subtitle/url items so the SPA never builds an
 * entity URL itself. Below two characters the response is empty, exactly like
 * legacy.
 *
 * The response stays backwards compatible: the flat `products` / `orders` /
 * `customers` keys the existing SearchModal reads are unchanged, and the new
 * keys (`stores`, `warehouses`, `transactions`, `staff`) plus `groups` are
 * additive.
 *
 * Legacy cached 5 minutes per *business* + query, so a warm cache handed one
 * user's results (including restricted-staff store scoping) to another user
 * in the same business. The key here includes the user id, so scopes cannot
 * bleed.
 */
class GlobalSearchController extends ApiController
{
    use ResolvesManagementContext;

    /** Legacy ignored terms shorter than this. */
    public const MIN_TERM_LENGTH = 2;

    /** Legacy capped every group at five rows; the full page may ask for more. */
    public const GROUP_LIMIT = 5;

    private const MAX_LIMIT = 10;

    /** Legacy's `Cache::remember(..., 300)`. */
    private const CACHE_TTL = 300;

    /**
     * Group order, heading, icon and the colour the modal paints the group
     * dot with. The order is the legacy render order with Orders slotted
     * after Customers.
     *
     * @var array<string, array{label: string, icon: string, color: string}>
     */
    private const GROUPS = [
        'products' => ['label' => 'Products', 'icon' => 'fi fi-rr-cube', 'color' => 'blue'],
        'stores' => ['label' => 'Stores', 'icon' => 'fi fi-rr-shop', 'color' => 'emerald'],
        'warehouses' => ['label' => 'Warehouses', 'icon' => 'fi fi-rr-warehouse-alt', 'color' => 'amber'],
        'customers' => ['label' => 'Customers', 'icon' => 'fi fi-rr-users-alt', 'color' => 'violet'],
        'orders' => ['label' => 'Orders', 'icon' => 'fi fi-rr-shopping-bag', 'color' => 'rose'],
        'transactions' => ['label' => 'Transactions', 'icon' => 'fi fi-rr-chart-histogram', 'color' => 'teal'],
        'staff' => ['label' => 'Staff', 'icon' => 'fi fi-rr-user', 'color' => 'slate'],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $term = trim((string) $request->string('q'));
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->integer('limit', self::GROUP_LIMIT)));

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return $this->ok($this->emptyPayload());
        }

        $payload = Cache::remember(
            "management.search.{$user->id}.".md5($term.'|'.$limit),
            self::CACHE_TTL,
            fn () => $this->results($request, $term, $limit),
        );

        return $this->ok($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function results(Request $request, string $term, int $limit): array
    {
        $sections = [
            'products' => $this->products($request, $term, $limit),
            'stores' => $this->stores($request, $term, $limit),
            'warehouses' => $this->warehouses($request, $term, $limit),
            'customers' => $this->customers($request, $term, $limit),
            'orders' => $this->orders($request, $term, $limit),
            'transactions' => $this->transactions($request, $term, $limit),
            'staff' => $this->staff($request, $term, $limit),
        ];

        $groups = [];

        foreach (self::GROUPS as $key => $meta) {
            if ($sections[$key] === []) {
                continue;
            }

            $groups[] = [
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'items' => $sections[$key],
            ];
        }

        return [...$sections, 'groups' => $groups];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(): array
    {
        $payload = array_fill_keys(array_keys(self::GROUPS), []);

        $payload['groups'] = [];

        return $payload;
    }

    /**
     * Products are store-scoped, matching legacy and the products list, and
     * business-scoped so a stray store id can never reach another tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function products(Request $request, string $term, int $limit): array
    {
        $user = $this->user($request);

        if (! $user->can('products view')) {
            return [];
        }

        $like = $this->like($term);

        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $this->storeIds($request))
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('product_code', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'product_code', 'name', 'amount', 'store_id'])
            ->map(fn (Product $product) => [
                // The flat keys the old modal reads.
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'amount' => (float) $product->amount,
                'store_id' => $product->store_id,
                // The pre-built item WS-34's modal renders.
                'title' => $product->name,
                'subtitle' => $product->product_code,
                'url' => '/products/'.$product->product_code,
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stores(Request $request, string $term, int $limit): array
    {
        $user = $this->user($request);

        if (! $user->can('stores view')) {
            return [];
        }

        $like = $this->like($term);

        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('store_id', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            // `stores.id` is qualified because the restricted-staff scope is a
            // pivot join that also has an `id` column.
            ->get(['stores.id', 'store_id', 'name', 'status'])
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'status' => $store->status,
                'title' => $store->name,
                'subtitle' => $store->store_id,
                // Store detail binds by store_id, not the numeric key.
                'url' => '/stores/'.$store->store_id,
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function warehouses(Request $request, string $term, int $limit): array
    {
        $user = $this->user($request);

        if (! $user->can('warehouses view')) {
            return [];
        }

        $like = $this->like($term);

        return $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('warehouse_code', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            // Qualified `warehouses.id` for the same pivot-join reason as stores.
            ->get(['warehouses.id', 'warehouse_code', 'name', 'status'])
            ->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'warehouse_code' => $warehouse->warehouse_code,
                'name' => $warehouse->name,
                'status' => $warehouse->status->value,
                'title' => $warehouse->name,
                'subtitle' => $warehouse->warehouse_code,
                'url' => '/warehouses/'.$warehouse->warehouse_code,
            ])->values()->all();
    }

    /**
     * The customer group WS-19 built: business scoped, `customers view` gated,
     * matched on account id and the concatenated full name as well as the four
     * legacy columns, and narrowed to the staffer's stores through their
     * orders.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customers(Request $request, string $term, int $limit): array
    {
        $user = $this->user($request);

        if (! $user->can('customers view')) {
            return [];
        }

        $like = $this->like($term);

        return Customer::query()
            ->where('business_id', $user->business_id)
            ->where('status', '!=', Customer::STATUS_DELETED)
            // Same scoping as WS-19's customer list: restricted staff see
            // only customers who have ordered from their stores.
            ->when($user->isRestrictedStaff(), fn (Builder $q) => $q->whereHas(
                'orders',
                fn (Builder $orders) => $orders->whereIn('store_id', $this->storeIds($request))
            ))
            ->where(fn (Builder $q) => $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('account_id', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]))
            ->limit($limit)
            ->get(['id', 'account_id', 'first_name', 'last_name', 'email', 'phone', 'status'])
            ->map(fn (Customer $customer) => [
                // The flat keys the old modal reads.
                'id' => $customer->id,
                'account_id' => $customer->account_id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                // The pre-built item WS-34's modal renders.
                'title' => $customer->full_name,
                'subtitle' => $customer->email ?? $customer->phone ?? $customer->account_id ?? '—',
                'url' => '/customers/'.$customer->account_id,
            ])->values()->all();
    }

    /**
     * The Orders group the current SPA searches for. Legacy's palette had no
     * Orders group, so this is an addition the module keeps rather than a
     * parity item.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orders(Request $request, string $term, int $limit): array
    {
        $user = $this->user($request);

        if (! $user->can('orders view')) {
            return [];
        }

        $like = $this->like($term);

        return Order::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $this->storeIds($request))
            ->where('order_number', 'like', $like)
            ->latest()
            ->limit($limit)
            ->get(['id', 'order_number', 'total', 'status', 'store_id'])
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'total' => (float) $order->total,
                'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                'store_id' => $order->store_id,
                'title' => $order->order_number,
                'subtitle' => $order->status_label,
                'url' => '/orders/'.$order->order_number,
            ])->values()->all();
    }

    /**
     * Transactions are matched on reference, like legacy. Scoping follows
     * WS-18: an order-linked payment is scoped through its order, an
     * invoice-linked one through its invoice (legacy joined orders, which
     * hid invoice payments from search entirely).
     *
     * @return array<int, array<string, mixed>>
     */
    private function transactions(Request $request, string $term, int $limit): array
    {
        $user = $this->user($request);

        if (! $user->can('transactions view')) {
            return [];
        }

        $like = $this->like($term);
        // Same fallback as WS-21: an unset business currency still renders ₦.
        $symbol = $this->currencySymbol($user->business?->currency ?: 'NGN');
        $storeIds = $this->transactionStoreIds($request);

        return Transaction::query()
            ->where('business_id', $user->business_id)
            ->where('reference', 'like', $like)
            ->when($user->isStaff(), fn (Builder $q) => $q->where(
                fn (Builder $scope) => $scope
                    ->whereHas('order', fn (Builder $order) => $order->whereIn('store_id', $storeIds))
                    ->orWhereHas('invoice', fn (Builder $invoice) => $invoice->whereIn('store_id', $storeIds))
            ))
            ->latest()
            ->limit($limit)
            ->get(['id', 'reference', 'amount', 'currency', 'status', 'created_at'])
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status?->value,
                'created_at' => $transaction->created_at?->toISOString(),
                'title' => $transaction->reference,
                'subtitle' => $symbol.number_format((float) $transaction->amount, 2)
                    .' · '.$transaction->created_at?->format('d M Y'),
                'url' => '/transactions/'.$transaction->reference,
            ])->values()->all();
    }

    /**
     * Legacy searched every non-deleted user in the business on name, email
     * and phone. The owner has no staff profile to open, so their row carries
     * a null url — the modal renders it as plain text.
     *
     * @return array<int, array<string, mixed>>
     */
    private function staff(Request $request, string $term, int $limit): array
    {
        $user = $this->user($request);

        if (! $user->can('staff view')) {
            return [];
        }

        $like = $this->like($term);

        return User::query()
            ->where('business_id', $user->business_id)
            ->where('status', '!=', 'deleted')
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'account_code', 'name', 'email', 'phone', 'role'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'account_code' => $member->account_code,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role,
                'title' => $member->name,
                'subtitle' => trim(($member->email ?? '').' · '.ucfirst((string) $member->role), ' ·'),
                'url' => $member->role === User::ROLE_BUSINESS_OWNER
                    ? null
                    : '/staff/'.$member->account_code,
            ])->values()->all();
    }

    /**
     * Accessible, non-deleted stores — the same scope WS-18/WS-26 use, and
     * the fix for "deleted warehouses leaked back into lists".
     *
     * @return Collection<int, int>
     */
    private function storeIds(Request $request): Collection
    {
        // Qualified `stores.id`: for restricted staff accessibleStores() is the
        // assignment pivot join, whose own `id` column would make a bare `id`
        // ambiguous (SQLSTATE 1052).
        return $this->user($request)
            ->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck('stores.id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Stores a staff member may see payments for — WS-18's rule: an explicit
     * store assignment wins over the permission heuristic (a non-restricted
     * staffer otherwise reads every store in the business), and a staffer
     * with no assignment falls back to their accessible stores.
     *
     * @return Collection<int, int>
     */
    private function transactionStoreIds(Request $request): Collection
    {
        $user = $this->user($request);

        if ($user->isStaff() && $user->assignedStores()->exists()) {
            return $user->assignedStores()
                ->where('status', '!=', Store::STATUS_DELETED)
                ->pluck('stores.id')
                ->map(fn ($id) => (int) $id);
        }

        return $this->storeIds($request);
    }

    private function like(string $term): string
    {
        return '%'.$term.'%';
    }

    /**
     * Same symbol map as WS-21's invoice screens; legacy hard-coded ₦.
     */
    private function currencySymbol(?string $currency): string
    {
        return [
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'GHS' => 'GH₵',
            'KES' => 'KSh',
            'ZAR' => 'R',
        ][strtoupper((string) $currency)] ?? strtoupper((string) $currency).' ';
    }
}
