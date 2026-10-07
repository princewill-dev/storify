<?php

namespace App\Services\Accounting;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-22 — the supplier write sequences: create, edit and delete.
 *
 * Each write keeps its own DB::transaction boundary and its post-commit audit
 * log, at the same points in the sequence as before this was lifted out of
 * the controller. The transactions wrap single rows and are preserved
 * verbatim — working code is rearranged, not rewritten.
 *
 * Reads (the list query and the detail eager loads) live in
 * SupplierRepository; HTTP responses and the "suppliers with bills cannot be
 * deleted" guard stay in the controller.
 */
final class SupplierService
{
    /**
     * @param  array<string, mixed>  $validated  validated StoreSupplierRequest data
     */
    public function create(User $user, array $validated): Supplier
    {
        $supplier = DB::transaction(fn () => Supplier::create([
            'business_id' => $user->business_id,
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
        ]));

        Log::info('api.management.supplier_created', [
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
        ]);

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $validated  validated StoreSupplierRequest data
     */
    public function update(User $user, Supplier $supplier, array $validated): void
    {
        DB::transaction(fn () => $supplier->update($validated));

        Log::info('api.management.supplier_updated', [
            'user_id' => $user->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    public function delete(User $user, Supplier $supplier): void
    {
        // Captured before the delete and logged after the transaction, as the
        // controller did before the refactor.
        $supplierId = $supplier->id;

        DB::transaction(fn () => $supplier->delete());

        Log::info('api.management.supplier_deleted', [
            'user_id' => $user->id,
            'supplier_id' => $supplierId,
        ]);
    }
}
