<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\RecordInvoicePaymentRequest;
use App\Http\Requests\Pos\StoreInvoiceRequest;
use App\Http\Resources\Pos\InvoiceDetailResource;
use App\Http\Resources\Pos\InvoiceResource;
use App\Http\Resources\Pos\InvoiceSummaryResource;
use App\Models\Invoice;
use App\Models\Store;
use App\Repositories\Pos\InvoiceRepository;
use App\Services\Pos\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * POS invoices (legacy `Pos\InvoiceController`), split into layers with the
 * behaviour untouched.
 *
 * This controller keeps the HTTP contract only: the legacy
 * `{success, data}` envelope (POS clients and this endpoint's tests read it),
 * the status codes, the message strings and the list's pagination meta. The
 * store reachability guard stays on the route middleware
 * (EnsurePosStoreAccess), answering 403 before validation can answer 422.
 *
 * Reads and query composition live in App\Repositories\Pos\InvoiceRepository,
 * the create/payment/send workflows and their transaction boundaries in
 * App\Services\Pos\InvoiceService, validation in App\Http\Requests\Pos\* and
 * response shaping in App\Http\Resources\Pos\*.
 *
 * The PIN gate stays here: its failure answers `{success: false, message:
 * 'Invalid PIN.'}`, which a FormRequest field error would replace.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceRepository $repository,
        private readonly InvoiceService $service,
    ) {}

    /**
     * GET /pos/stores/{store}/invoices — the store's invoices, filtered by
     * the status tab and the number/recipient search.
     */
    public function index(Request $request, Store $store): JsonResponse
    {
        // Presence, trimming and the enum check are read here with the same
        // filled()/in_array()/trim() semantics the inline query used (an
        // invalid status is ignored, not a 422); the repository composes the
        // query from the resolved values.
        $status = $request->filled('status') && in_array($request->status, array_column(InvoiceStatus::cases(), 'value'))
            ? $request->status
            : null;

        $invoices = $this->repository->paginateForStore($store, [
            'status' => $status,
            'q' => $request->filled('q') ? trim($request->q) : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'invoices' => $invoices->getCollection()
                    ->map(fn (Invoice $invoice) => (new InvoiceSummaryResource($invoice))->resolve($request))
                    ->all(),
                'pagination' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page' => $invoices->lastPage(),
                    'total' => $invoices->total(),
                ],
            ],
        ]);
    }

    /**
     * GET /pos/stores/{store}/invoices/{invoiceId} — the invoice document.
     */
    public function show(Request $request, Store $store, $invoiceId): JsonResponse
    {
        $invoice = $this->repository->findForStore($store, $invoiceId, ['items', 'customer', 'transactions.paymentMethod']);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => (new InvoiceDetailResource($invoice))->resolve($request),
            ],
        ]);
    }

    /**
     * POST /pos/stores/{store}/invoices — create; totals are always
     * recomputed by the service, never read from the payload.
     */
    public function store(StoreInvoiceRequest $request, Store $store): JsonResponse
    {
        $invoice = $this->service->createInvoice($store, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'data' => (new InvoiceResource($invoice))->resolve($request),
        ], 201);
    }

    /**
     * POST /pos/stores/{store}/invoices/{invoiceId}/send — send (or resend)
     * the invoice mail. The service swallows mail failures and returns
     * quietly when no recipient is reachable, so this answers "Invoice sent."
     * either way, exactly as before.
     */
    public function sendInvoice(Request $request, Store $store, $invoiceId): JsonResponse
    {
        $invoice = $this->repository->findForStore($store, $invoiceId);

        if ($invoice->status === InvoiceStatus::PAID || $invoice->status === InvoiceStatus::VOID) {
            return response()->json(['success' => false, 'message' => 'Cannot send a paid or voided invoice.'], 400);
        }

        $this->service->send($invoice);

        return response()->json(['success' => true, 'message' => 'Invoice sent.']);
    }

    /**
     * POST /pos/stores/{store}/invoices/{invoiceId}/record-payment — one
     * manual payment, PIN-gated when the operator has a POS PIN.
     */
    public function recordPayment(RecordInvoicePaymentRequest $request, Store $store, $invoiceId): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $invoice = $this->repository->findForStore($store, $invoiceId);

        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID])) {
            return response()->json(['success' => false, 'message' => 'Cannot record payment on this invoice.'], 400);
        }

        if ($user->pos_pin && ! Hash::check($data['pin'], $user->pos_pin)) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
        }

        $this->service->recordPayment($invoice, $user, $data);

        $invoice = $this->repository->refreshDetail($invoice);

        return response()->json([
            'success' => true,
            'data' => (new InvoiceResource($invoice))->resolve($request),
        ]);
    }
}
