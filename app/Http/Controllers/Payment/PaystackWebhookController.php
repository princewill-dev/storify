<?php

namespace App\Http\Controllers\Payment;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function __construct(protected PaystackService $paystackService) {}

    /**
     * Handle incoming Paystack webhook events.
     * Public endpoint — no auth.
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json(['status' => 'missing signature'], 400);
        }

        $gateway = $this->verifySignature($payload, $signature);

        if (! $gateway) {
            Log::warning('paystack.webhook.unverified_signature', ['ip' => $request->ip()]);

            return response()->json(['status' => 'unverified'], 401);
        }

        $event = json_decode($payload);

        if (! $event || ! isset($event->event)) {
            return response()->json(['status' => 'invalid payload'], 400);
        }

        Log::info('paystack.webhook.received', [
            'event' => $event->event,
            'business_id' => $gateway->business_id,
        ]);

        return match ($event->event) {
            'charge.success' => $this->handleChargeSuccess($event, $gateway),
            default => response()->json(['status' => 'unhandled event']),
        };
    }

    private function handleChargeSuccess($event, $gateway)
    {
        $data = $event->data;
        $reference = $data->reference ?? null;

        if (! $reference) {
            return response()->json(['status' => 'no reference']);
        }

        $transaction = Transaction::where('reference', $reference)->first();

        if (! $transaction) {
            Log::warning('paystack.webhook.transaction_not_found', [
                'reference' => $reference,
                'business_id' => $gateway->business_id,
            ]);

            return response()->json(['status' => 'transaction not found'], 404);
        }

        $transactionStatus = $transaction->status instanceof TransactionStatus
            ? $transaction->status->value
            : $transaction->status;

        if ($transactionStatus === TransactionStatus::CONFIRMED->value) {
            return response()->json(['status' => 'already processed']);
        }

        try {
            // Double verify with business-specific keys
            $verification = $this->paystackService
                ->usingGateway($gateway)
                ->doubleVerifyPayment($reference);

            if ($verification['success'] && ($verification['data']['status'] ?? '') === 'success') {
                DB::transaction(function () use ($transaction, $data) {
                    $feesKobo = isset($data->fees) ? (int) $data->fees : null;
                    $amountKobo = (int) round($transaction->amount * 100);

                    $transaction->update([
                        'status' => TransactionStatus::CONFIRMED->value,
                        'paid_at' => now(),
                        'gateway_reference' => $data->id ?? null,
                        'gateway_response' => $data,
                        'fee_kobo' => $feesKobo,
                        'net_kobo' => $feesKobo !== null ? max(0, $amountKobo - $feesKobo) : null,
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'webhook_received' => true,
                            'webhook_data' => $data,
                        ]),
                    ]);

                    $order = $transaction->order;

                    if ($order) {
                        $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                        if ($order->isFullyPaid()) {
                            $order->status = OrderStatus::ACCEPTED;
                        }
                        $order->save();

                        $store = $order->store;
                        if ($store) {
                            $amountInKobo = (int) round($transaction->amount * 100);
                            $balanceBefore = (int) $store->balance;
                            $store->creditBalance($amountInKobo);
                            $transaction->update([
                                'balance_updated_at' => now(),
                                'store_balance_before' => $balanceBefore,
                                'store_balance_after' => (int) $store->fresh()->balance,
                            ]);
                        }
                    }
                });

                Log::info('paystack.webhook.payment_verified', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                ]);

                $ledger = app(\App\Services\Accounting\LedgerPostingService::class);
                $ledger->safe(fn () => $ledger->postPaymentReceived($transaction));

                return response()->json(['status' => 'success']);
            }

            Log::warning('paystack.webhook.verification_failed', [
                'reference' => $reference,
                'verification' => $verification,
            ]);

            return response()->json(['status' => 'verification failed']);

        } catch (\Throwable $e) {
            Log::error('paystack.webhook.error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /**
     * Verify HMAC-SHA512 signature against all active business gateway secrets.
     */
    private function verifySignature(string $payload, string $signature): ?object
    {
        $paystackId = PaymentMethod::where('code', 'paystack')->value('id');
        $rows = DB::table('business_payment_method')
            ->where('payment_method_id', $paystackId)->where('is_active', true)->get();

        foreach ($rows as $row) {
            $config = json_decode($row->config, true);
            $secretKey = $config['secret_key'] ?? null;
            if (! $secretKey) {
                continue;
            }

            $computed = hash_hmac('sha512', $payload, $secretKey);

            if (hash_equals($computed, $signature)) {
                $gw = (object) ['public_key' => $config['public_key'] ?? '', 'secret_key' => $secretKey, 'id' => $row->id, 'business_id' => $row->business_id];
                $gw->id = $row->id;
                $gw->business_id = $row->business_id;

                return $gw;
            }
        }

        return null;
    }
}
