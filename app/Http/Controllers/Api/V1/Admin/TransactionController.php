<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->with(['order:id,order_number,store_id', 'invoice:id,invoice_number,store_id', 'paymentMethod:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('reference', 'like', '%'.$request->string('q').'%'))
            ->orderBy(
                in_array($request->string('sort')->toString(), ['reference', 'amount', 'status', 'paid_at', 'created_at'], true)
                    ? $request->string('sort')->toString()
                    : 'created_at',
                $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc'
            )
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $transactions->getCollection()->map(fn (Transaction $transaction) => $this->payload($transaction))->values()->all(),
            null,
            200,
            $this->paginationMeta($transactions)
        );
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $transaction->load(['order.customer:id,first_name,last_name', 'invoice.store:id,name', 'paymentMethod:id,name']);

        return $this->ok(['transaction' => $this->payload($transaction, detailed: true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Transaction $transaction, bool $detailed = false): array
    {
        $data = [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
            'order' => $transaction->order?->order_number,
            'invoice' => $transaction->invoice?->invoice_number,
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data['gateway_reference'] = $transaction->gateway_reference;
            // The combined figure, matching the business-facing read model — an
            // admin looking at the same payment should see the same number the
            // business sees, or every support call becomes a reconciliation.
            $processingFee = $transaction->processingFeeKobo();
            $data['processing_fee'] = $processingFee !== null ? Naira::floatFromKobo($processingFee) : null;
            // Deprecated alias, same figure — see the note on the shape of this
            // in Management\TransactionResource. Both SPAs and the API deploy
            // separately here, and carrying both keys means neither order leaves
            // a live screen showing a dash where a fee used to be.
            $data['fee'] = $processingFee !== null ? Naira::floatFromKobo($processingFee) : null;
            $net = $transaction->netKobo();
            $data['net'] = $net !== null ? Naira::floatFromKobo($net) : null;
            // Storify's own cost of taking this payment, which is no longer part
            // of what the business is shown. The provider's charge out of *our*
            // balance on a sale we processed; zero on one the business charged
            // through its own keys, because there the business pays it.
            //
            // Safe to expose here and not in the business API because this
            // payload is built inline from the model rather than through
            // Management\TransactionResource — the business's API cannot reach
            // this field, which is the property that matters for it.
            $data['provider_fee'] = Naira::floatFromKobo(max(0, (int) ($transaction->fee_kobo ?? 0)));
            $data['provider_fee_bearer'] = $transaction->providerFeeBearer()->value;
            $data['customer'] = $transaction->order?->customer?->full_name;
            $data['gateway_response'] = $transaction->gateway_response;
        }

        return $data;
    }
}
