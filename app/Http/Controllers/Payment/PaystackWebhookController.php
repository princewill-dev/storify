<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
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

        if (!$signature) {
            return response()->json(['status' => 'missing signature'], 400);
        }

        $gateway = $this->verifySignature($payload, $signature);

        if (!$gateway) {
            Log::warning('paystack.webhook.unverified_signature', ['ip' => $request->ip()]);
            return response()->json(['status' => 'unverified'], 401);
        }

        $event = json_decode($payload);

        if (!$event || !isset($event->event)) {
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

        if (!$reference) {
            return response()->json(['status' => 'no reference']);
        }

        $transaction = Transaction::where('reference', $reference)->first();

        if (!$transaction) {
            Log::warning('paystack.webhook.transaction_not_found', [
                'reference' => $reference,
                'business_id' => $gateway->business_id,
            ]);
            return response()->json(['status' => 'transaction not found'], 404);
        }

        $transactionStatus = $transaction->status instanceof \App\Enums\TransactionStatus
            ? $transaction->status->value
            : $transaction->status;

        if ($transactionStatus === 'completed') {
            return response()->json(['status' => 'already processed']);
        }

        try {
            // Double verify with business-specific keys
            $verification = $this->paystackService
                ->usingGateway($gateway)
                ->doubleVerifyPayment($reference);

            if ($verification['success'] && ($verification['data']['status'] ?? '') === 'success') {
                DB::transaction(function () use ($transaction, $data) {
                    $transaction->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'webhook_received' => true,
                            'webhook_data' => $data,
                        ]),
                    ]);

                    if ($transaction->order) {
                        $transaction->order->update([
                            'payment_method' => 'Paystack',
                        ]);
                    }
                });

                Log::info('paystack.webhook.payment_verified', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                ]);

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
        $paystackId = \App\Models\PaymentMethod::where('code', 'paystack')->value('id');
        $rows = \Illuminate\Support\Facades\DB::table('business_payment_method')
            ->where('payment_method_id', $paystackId)->where('is_active', true)->get();

        foreach ($rows as $row) {
            $config = json_decode($row->config, true);
            $secretKey = $config['secret_key'] ?? null;
            if (!$secretKey) {
                continue;
            }

            $computed = hash_hmac('sha512', $payload, $secretKey);

            if (hash_equals($computed, $signature)) {
                $gw = new \App\Models\BusinessGateway(['public_key' => $config['public_key'] ?? '', 'secret_key' => $secretKey]);
                $gw->id = $row->id; $gw->business_id = $row->business_id;
                return $gw;
            }
        }

        return null;
    }
}
