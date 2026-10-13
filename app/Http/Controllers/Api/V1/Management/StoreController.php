<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Store;
use App\Models\User;
use App\Services\Payments\Custody\WalletSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends ApiController
{
    public function __construct(
        private readonly WalletSummaryService $wallet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $stores = $user->accessibleStores()
            ->where('status', '!=', 'deleted')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('slug', 'like', $term));
            })
            // `custody_enabled` is the whole of `balance_source`, and the payload
            // asks for it per row: without the eager load that is one query per
            // store to read a boolean. Selected explicitly so the row is not
            // fetched whole to answer it.
            ->with('business:id,custody_enabled')
            ->withCount(['products', 'orders'])
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 20));

        // Once for the page, not once per row: each store's wallet line is a slice
        // of the same payable plan, and the walk behind it reads the business's
        // sale history.
        $blocks = $user->business !== null ? $this->wallet->storeBlocks($user->business) : [];

        return $this->ok(
            $stores->getCollection()->map(fn (Store $store) => $this->payload($store, $blocks))->values()->all(),
            null,
            200,
            [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ]
        );
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $store->loadCount(['products', 'orders']);
        $store->load(['ownershipType', 'businessType']);

        $business = $store->business;

        return $this->ok(['store' => $this->payload(
            $store,
            $business !== null ? [$store->id => $this->wallet->storeWalletBlock($business, $store)] : [],
        )]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $walletBlocks  keyed by store id
     * @return array<string, mixed>
     */
    private function payload(Store $store, array $walletBlocks = []): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'pos_enabled' => (bool) $store->pos_enabled,
            'balance' => (int) $store->balance,
            // Which of the two figures above is the real one. `stores.balance` is
            // never credited for a sale Storify collected — the money goes to the
            // wallet instead — so a custody business was shown ₦0.00 here by every
            // screen while its money sat in `businesses.available_balance`. Both
            // keys are always sent; `balance_source` says which to draw, and
            // `wallet` carries the other one's figures.
            // A store with no business row cannot be under custody, so the legacy
            // figure is the honest answer rather than a guess at the other one.
            'balance_source' => $store->business !== null
                ? $this->wallet->balanceSource($store->business)
                : WalletSummaryService::SOURCE_STORE,
            'wallet' => $walletBlocks[$store->id] ?? null,
            'payment_mode' => $store->payment_mode,
            'products_count' => $store->products_count ?? null,
            'orders_count' => $store->orders_count ?? null,
            'customers_count' => $store->customers_count ?? null,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            'created_at' => $store->created_at?->toISOString(),
        ];
    }

    private function authorizeStore(Request $request, Store $store): void
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $store->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($store->id)->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }
}
