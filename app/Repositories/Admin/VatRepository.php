<?php

namespace App\Repositories\Admin;

use App\Models\Vat;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * WS-12 — platform VAT-rate reads.
 *
 * This layer builds queries and never opens a transaction and never calls
 * abort(): the write workflows and their transaction boundaries live in
 * VatService and the controller owns the HTTP shape. The write side's only
 * queries are single `update` calls that must run inside those transactions,
 * so they are deliberately not wrapped here.
 */
final class VatRepository
{
    /**
     * The console list: the active rate leads, then newest effective date and
     * id — the ordering the list has always used — paginated with the query
     * string preserved.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForAdmin(array $filters): LengthAwarePaginator
    {
        return Vat::query()
            ->orderByDesc('active')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }
}
