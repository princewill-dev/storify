<?php

namespace App\Repositories\Pos;

use App\Models\Order;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reads for the POS orders API.
 *
 * The cashier's history list and the order a refund workflow locks live here.
 * Query composition only: this layer never opens a transaction, never aborts
 * an HTTP request and never writes. The store scoping both queries carry is
 * load-bearing — the route-bound store is the only thing keeping one store's
 * orders out of another's list — which is what earns these methods a name.
 */
final class OrderRepository
{
    /**
     * The cashier's order history for one store: POS orders only, the order
     * number / product-name search, the eager loads and the newest-first
     * ordering, paginated 20 per page with the query string carried over.
     *
     * Scoping is two-layered and deliberate. An open drawer narrows the list
     * to that session's orders; with no open session the cashier instead sees
     * their own orders in the store. So the same route shows a different set
     * before and after the session opens or closes, and the fallback must stay
     * the staff filter rather than the whole store.
     *
     * The search arrives already resolved by the caller with the same
     * filled()/string() semantics the inline query used; a null means "not
     * filled" and skips the filter entirely.
     *
     * @return LengthAwarePaginator<Order>
     */
    public function paginateHistory(Store $store, User $user, ?string $search): LengthAwarePaginator
    {
        $session = PosSession::query()
            ->where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        return Order::query()
            ->where('store_id', $store->id)
            ->where('source', 'pos')
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('items', fn ($items) => $items->where('product_name', 'like', "%{$search}%"));
                });
            })
            ->with(['items', 'transactions.paymentMethod'])
            ->when($session !== null, fn ($query) => $query->where('pos_session_id', $session->id))
            ->when($session === null, fn ($query) => $query->where('staff_id', $user->id))
            ->latest()
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * The order the refund workflow locks before writing its transaction row.
     *
     * The caller owns the surrounding DB::transaction; findOrFail keeps the
     * 404 for an id that does not exist, and the store filter keeps one
     * store's cashier from refunding another store's order.
     */
    public function findLockedForStore(Store $store, int $orderId): Order
    {
        return Order::query()
            ->where('store_id', $store->id)
            ->lockForUpdate()
            ->findOrFail($orderId);
    }
}
