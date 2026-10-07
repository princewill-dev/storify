<?php

namespace App\Http\Controllers\Api\V1\Management\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Accounting\IndexSupplierRequest;
use App\Http\Requests\Management\Accounting\StoreSupplierRequest;
use App\Http\Resources\Management\Accounting\SupplierDetailResource;
use App\Http\Resources\Management\Accounting\SupplierSummaryResource;
use App\Models\Supplier;
use App\Repositories\Accounting\SupplierRepository;
use App\Services\Access\TenantGuard;
use App\Services\Accounting\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-22 — Accounting: Suppliers.
 *
 * The legacy module rebuilt on the API: paginated search with the Outstanding
 * column, create/edit/delete with the "suppliers with bills cannot be
 * deleted" guard, and a detail screen carrying the contact card plus the
 * supplier's bills and payment history.
 *
 * `GET accounting/suppliers` re-registers the URI the shared AccountingController
 * already serves; feature modules load after the shared route file, so this
 * registration wins and the legacy search/outstanding payload lands on the
 * endpoint the SPA already calls. Every other route is new.
 *
 * Layering: HTTP shape (statuses, messages, envelope, pagination meta) stays
 * here; field validation lives in App\Http\Requests\Management\Accounting,
 * the list query and detail eager loads in
 * App\Repositories\Accounting\SupplierRepository, the create/edit/delete
 * transaction-and-audit sequences in App\Services\Accounting\SupplierService,
 * and the payloads in App\Http\Resources\Management\Accounting.
 */
class SupplierController extends ApiController
{
    use ResolvesManagementContext;

    public function index(IndexSupplierRequest $request, SupplierRepository $repository): JsonResponse
    {
        $suppliers = $repository->paginateForBusiness(
            $this->user($request)->business_id,
            $request->validated(),
        );

        return $this->ok(
            ['suppliers' => SupplierSummaryResource::collection($suppliers->getCollection())->resolve()],
            null,
            200,
            $this->paginationMeta($suppliers),
        );
    }

    public function show(Request $request, Supplier $supplier, SupplierRepository $repository): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $repository->loadForDetail($supplier);

        return $this->ok(['supplier' => (new SupplierDetailResource($supplier))->resolve($request)]);
    }

    public function store(StoreSupplierRequest $request, SupplierService $service): JsonResponse
    {
        $supplier = $service->create($this->user($request), $request->validated());

        return $this->ok(
            ['supplier' => (new SupplierSummaryResource($supplier))->resolve($request)],
            'Supplier added.',
            201,
        );
    }

    public function update(StoreSupplierRequest $request, Supplier $supplier, SupplierService $service): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        $service->update($this->user($request), $supplier, $request->validated());

        return $this->ok(
            ['supplier' => (new SupplierSummaryResource($supplier->fresh()))->resolve($request)],
            'Supplier updated.',
        );
    }

    public function destroy(Request $request, Supplier $supplier, SupplierService $service): JsonResponse
    {
        $this->authorizeSupplier($request, $supplier);

        // Guard parity with legacy: bills are the audit trail for money owed,
        // so a supplier that has any (void ones included) cannot be removed.
        if ($supplier->bills()->exists()) {
            return $this->error('Suppliers with bills cannot be deleted.');
        }

        $service->delete($this->user($request), $supplier);

        return $this->ok([], 'Supplier deleted.');
    }

    private function authorizeSupplier(Request $request, Supplier $supplier): void
    {
        app(TenantGuard::class)->authorizeBusiness($supplier, $this->user($request), 'You do not have access to this supplier.');
    }
}
