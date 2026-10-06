<?php

namespace App\Http\Resources\Management\Invoice;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WS-21 — the create/edit form payload: tenant-scoped customer and store
 * pickers, the legacy defaults (first reachable store preselected, issue date
 * today, due date two weeks out) and the status/currency meta.
 *
 * Served from the invoices module rather than the customers/stores endpoints:
 * the cashier role holds `invoices view` without `stores view`, so the form
 * must carry its own tenant-scoped options to stay usable.
 */
final class InvoiceFormOptionsResource
{
    /**
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Store>  $stores
     * @param  array<int, array{value: string, label: string}>  $statuses
     */
    public function __construct(
        private readonly Collection $customers,
        private readonly Collection $stores,
        private readonly array $statuses,
        private readonly string $currency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(Request $request): array
    {
        $customers = CustomerOptionResource::collection($this->customers)->resolve($request);
        $stores = StoreOptionResource::collection($this->stores)->resolve($request);

        return [
            'customers' => $customers,
            'stores' => $stores,
            'defaults' => [
                // Legacy preselected the first reachable store on create and
                // defaulted the due date two weeks out.
                'store_id' => $stores[0]['id'] ?? null,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ],
            'statuses' => $this->statuses,
            'currency' => $this->currency,
        ];
    }
}
