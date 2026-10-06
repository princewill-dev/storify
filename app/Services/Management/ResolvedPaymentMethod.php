<?php

namespace App\Services\Management;

use App\Models\PaymentMethod;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * WS-11 — one `{type}/{id}` payment-method resolution: the method the id
 * resolves to, the context row behind it (the business gateway pivot row for
 * `gateway`, the StoreBank for `bank`) and the assigned/available store
 * queries around it. The queries are built here but only executed by the
 * method-info action that needs both lists.
 */
final class ResolvedPaymentMethod
{
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly string $type,
        public readonly object $row,
        public readonly Builder $assignedQuery,
        public readonly Builder $availableQuery,
    ) {}
}
