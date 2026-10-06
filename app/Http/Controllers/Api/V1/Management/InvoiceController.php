<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Invoice\IndexInvoiceRequest;
use App\Http\Requests\Management\Invoice\RecordInvoicePaymentRequest;
use App\Http\Requests\Management\Invoice\StoreInvoiceRequest;
use App\Http\Resources\Management\Invoice\InvoiceDetailResource;
use App\Http\Resources\Management\Invoice\InvoiceDocumentResource;
use App\Http\Resources\Management\Invoice\InvoiceFormOptionsResource;
use App\Http\Resources\Management\Invoice\InvoiceSummaryResource;
use App\Models\Invoice;
use App\Repositories\Management\InvoiceRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\InvoiceService;
use App\Support\Money\Naira;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * WS-21 — Invoices (legacy `Management\InvoiceController`).
 *
 * The legacy module is reproduced end-to-end: list with stats/tabs/search,
 * draft-only create/edit, the printable DomPDF artefact, send/remind with a
 * public payment token, mark-paid and partial record-payment (both crediting
 * the store balance and posting to the ledger), void, and draft-only delete.
 *
 * Money columns on `invoices` are naira decimals (the schema legacy shipped),
 * so every computation happens in integer kobo and only converts at the
 * persistence boundary — no float arithmetic ever decides a stored amount.
 * That maths and the workflows (transactions, mail, ledger) live in
 * App\Services\Management\InvoiceService (which names the Naira converters it
 * uses), the reads in App\Repositories\Management\InvoiceRepository, validation
 * in App\Http\Requests\Management\Invoice\* and payloads in
 * App\Http\Resources\Management\Invoice\*. This controller keeps the HTTP
 * contract — status codes, message strings, the envelope, pagination meta —
 * and the authorization guards.
 *
 * Fixes over legacy, noted at their sites: `store_id`/`customer_id` are
 * checked against the business instead of a bare `exists:` rule (the legacy
 * rule let a user attach another business's store; now enforced in
 * StoreInvoiceRequest and re-checked here); `sent_at` is stamped for "Save &
 * Send" (legacy dropped it); the created customer is linked to the invoice;
 * and a paid invoice can no longer be voided.
 */
class InvoiceController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly InvoiceRepository $repository,
        private readonly InvoiceService $service,
        private readonly TenantGuard $tenantGuard,
    ) {}

    /**
     * GET /management/invoices — stats row, status tabs, search, list.
     */
    public function index(IndexInvoiceRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $this->user($request);

        $invoices = $this->repository->paginateForUser($user, $filters);

        return $this->ok([
            'invoices' => $invoices->getCollection()
                ->map(fn (Invoice $invoice) => $this->summary($invoice)->resolve($request))
                ->all(),
            'stats' => $this->repository->stats($user),
            'statuses' => $this->statusOptions(),
            'currency' => $this->currency($request),
        ], null, 200, $this->paginationMeta($invoices));
    }

    /**
     * GET /management/invoices/form-options — the create/edit form pickers.
     *
     * Served from this module rather than the customers/stores endpoints: the
     * cashier role holds `invoices view` without `stores view`, so the form
     * must carry its own tenant-scoped options to stay usable.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->ok((new InvoiceFormOptionsResource(
            $this->repository->customerOptions($user),
            $this->repository->storeOptions($user),
            $this->statusOptions(),
            $this->currency($request),
        ))->resolve($request));
    }

    /**
     * POST /management/invoices — totals are always recomputed here.
     */
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->service->createInvoice(
            $this->user($request),
            $request->validated(),
            $request->boolean('finalize'),
        );

        return $this->ok(['invoice' => $this->detail($invoice)], 'Invoice created.', 201);
    }

    /**
     * GET /management/invoices/{invoice} — the document read model.
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        return $this->ok(['invoice' => $this->detail($invoice)]);
    }

    /**
     * PUT /management/invoices/{invoice} — draft-only (403 parity).
     */
    public function update(StoreInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (! $invoice->isDraft()) {
            return $this->error('Only draft invoices can be edited.', 403);
        }

        $this->service->updateInvoice($invoice, $request->validated(), $request->boolean('finalize'));

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice updated.');
    }

    /**
     * DELETE /management/invoices/{invoice} — drafts only (403 parity).
     */
    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (! $invoice->isDraft()) {
            return $this->error('Only draft invoices can be deleted.', 403);
        }

        $this->service->delete($invoice, $this->user($request));

        return $this->ok([], 'Invoice deleted.');
    }

    /**
     * GET /management/invoices/{invoice}/pdf — the print artefact.
     */
    public function pdf(Request $request, Invoice $invoice): Response
    {
        $this->authorizeInvoice($request, $invoice);

        $invoice = $this->repository->loadDocument($invoice);

        $pdf = Pdf::loadView('pdf.ws21.invoice', (new InvoiceDocumentResource(
            $invoice,
            $this->service->discountKobo($invoice),
        ))->toArray($request));

        return $pdf->download($invoice->invoice_number.'.pdf');
    }

    /**
     * POST /management/invoices/{invoice}/send — "Send Invoice" and "Remind".
     *
     * Issues/reuses the public `payment_token`, queues `InvoiceMail` with the
     * payment URL, moves draft -> sent, and posts the invoice to the ledger
     * (`postInvoice`). Legacy failed softly on mail errors; the honest port
     * surfaces the failure instead of pretending the invoice was sent.
     */
    public function send(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID], true)) {
            return $this->error('Cannot send a '.$invoice->status->label().' invoice.', 422);
        }

        $user = $this->user($request);

        try {
            $to = $this->service->send($invoice, $user);
        } catch (\Throwable $e) {
            Log::error('api.management.invoice_send_failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The invoice email could not be queued. Please try again.', 500);
        }

        if (! $to) {
            return $this->error('Add a recipient email before sending this invoice.', 422);
        }

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice sent to '.$to.'.');
    }

    /**
     * POST /management/invoices/{invoice}/mark-paid — settle the balance.
     */
    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        $outcome = $this->service->markPaid($invoice, $this->user($request));

        if ($outcome['failure'] !== null) {
            return $this->error($outcome['failure'], 422);
        }

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice marked as paid.');
    }

    /**
     * POST /management/invoices/{invoice}/record-payment — manual (partial)
     * payment, confirmed with the operator's current password.
     *
     * The password gate is a deliberate legacy security feature (the audit
     * flagged it as such) — kept, not cloned away.
     */
    public function recordPayment(RecordInvoicePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if (in_array($invoice->status, [InvoiceStatus::PAID, InvoiceStatus::VOID], true)) {
            return $this->error('Cannot record payment on a '.$invoice->status->label().' invoice.', 422);
        }

        $data = $request->validated();
        $amountKobo = Naira::koboFromRounded((float) $data['amount']);

        $this->service->recordPayment($invoice, $this->user($request), $data, $amountKobo);

        $method = ucfirst(str_replace('_', ' ', $data['payment_method']));

        return $this->ok(
            ['invoice' => $this->detail($invoice->fresh())],
            'Payment of ₦'.number_format(Naira::floatFromKobo($amountKobo), 2).' via '.$method.' recorded successfully.',
        );
    }

    /**
     * POST /management/invoices/{invoice}/void — legacy also stamped
     * `voided_at`; refusing a paid invoice is the one guard legacy lacked.
     */
    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        if ($invoice->status === InvoiceStatus::PAID) {
            return $this->error('A paid invoice cannot be voided.', 422);
        }

        if ($invoice->status === InvoiceStatus::VOID) {
            return $this->error('This invoice is already void.', 422);
        }

        $this->service->void($invoice, $this->user($request));

        return $this->ok(['invoice' => $this->detail($invoice->fresh())], 'Invoice voided.');
    }

    /**
     * Response shaping for the list row. Remaining-balance maths is the
     * service's; the resource only converts it for JSON.
     */
    private function summary(Invoice $invoice): InvoiceSummaryResource
    {
        return new InvoiceSummaryResource($invoice, $this->service->remainingKobo($invoice));
    }

    /**
     * Response shaping for the document read model, with the relations
     * eager-loaded by the repository.
     */
    private function detail(Invoice $invoice): InvoiceDetailResource
    {
        $invoice = $this->repository->loadDetail($invoice);

        return new InvoiceDetailResource(
            $invoice,
            $this->service->remainingKobo($invoice),
            $this->service->discountKobo($invoice),
        );
    }

    /**
     * Invoices are business-level; a restricted (store-assigned) staff member
     * only reaches the invoices raised against their stores. The tenant
     * FormRequests repeat this guard before validation (see their docblocks),
     * but every action asserts it here too.
     */
    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        $user = $this->user($request);

        $this->tenantGuard->authorizeBusiness($invoice, $user, 'You do not have access to this invoice.');

        if ($user->isRestrictedStaff()) {
            $this->tenantGuard->authorizeStoreId($user, (int) $invoice->store_id, 'You do not have access to this invoice.');
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            fn (InvoiceStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            InvoiceStatus::cases(),
        );
    }

    private function currency(Request $request): string
    {
        return $this->user($request)->business?->currency ?: 'NGN';
    }
}
