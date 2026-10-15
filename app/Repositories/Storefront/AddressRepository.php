<?php

namespace App\Repositories\Storefront;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Reads and the two multi-row writes behind the customer address book.
 *
 * Every query is scoped to one customer id, and the reads are further limited
 * to `is_snapshot = false`: an order's delivery copy is not the customer's to
 * see in a book, let alone edit, and listing them would show one entry per
 * order ever placed.
 *
 * The two writes here are the ones that touch more than one row — clearing and
 * promoting the default — and they have no business logic beyond "exactly one
 * saved address is the default while the book is non-empty". That invariant is
 * the reason they live together: it is maintained from four call sites, and a
 * second implementation of it is how two defaults appear.
 */
final class AddressRepository
{
    /**
     * The customer's saved addresses, default first.
     *
     * @return EloquentCollection<int, DeliveryAddress>
     */
    public function saved(Customer $customer): EloquentCollection
    {
        return $this->savedQuery($customer)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One saved address by its id, or a 404.
     *
     * firstOrFail rather than a null-and-check, because the refusal has to be
     * indistinguishable from an address that does not exist: another
     * customer's id must not be discoverable by asking for it.
     */
    public function findSaved(Customer $customer, string $id): DeliveryAddress
    {
        return $this->savedQuery($customer)
            ->whereKey($id)
            ->firstOrFail();
    }

    public function hasSaved(Customer $customer): bool
    {
        return $this->savedQuery($customer)->exists();
    }

    /**
     * Clear the default flag across the customer's saved addresses.
     *
     * `$exceptId` leaves one row alone, so promoting a new default is a clear
     * plus a set rather than a window in which the customer has none.
     */
    public function clearDefault(Customer $customer, ?int $exceptId = null): void
    {
        $query = $this->savedQuery($customer)->where('is_default', true);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update(['is_default' => false]);
    }

    /**
     * Make the newest saved address the default, unless one already is.
     *
     * Called after a delete, and after an explicit `is_default: false`. The
     * bookkeeping has to end somewhere: with entries but no default, checkout
     * has nothing to preselect, and the customer would have to pick an address
     * they already saved every single time.
     */
    public function promoteDefault(Customer $customer): void
    {
        $current = $this->savedQuery($customer)->where('is_default', true)->exists();

        if ($current) {
            return;
        }

        $next = $this->savedQuery($customer)->orderByDesc('id')->first();

        if ($next) {
            $next->update(['is_default' => true]);
        }
    }

    /**
     * The book, as a query — the one place the customer and snapshot scoping
     * is written down.
     *
     * @return Builder<DeliveryAddress>
     */
    private function savedQuery(Customer $customer): Builder
    {
        return DeliveryAddress::query()
            ->where('customer_id', $customer->id)
            ->where('is_snapshot', false);
    }
}
