<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewOrderAdminMail;
use App\Mail\OrderReceivedMail;
use App\Mail\VendorOrderNotificationMail;

class PaystackController extends Controller
{
    protected PaystackService $paystack;

    public function __construct(PaystackService $paystack)
    {
        $this->paystack = $paystack;
    }

    /**
     * Initialize payment
     */
    public function initialize(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'exists:orders,id'],
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $order = Order::findOrFail($request->order_id);

            // Check if order is already paid
            if ($order->payment_status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order has already been paid',
                ], 400);
            }

            // Generate unique reference
            $reference = $this->paystack->generateReference('ORD');

            // Initialize payment
            $result = $this->paystack->initializePayment([
                'email' => $request->email,
                'amount' => (int) ($order->total * 100),
                'currency' => 'NGN',
                'reference' => $reference,
                'callback_url' => route('payment.paystack.callback'),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'customer_name' => $order->customer_name ?? 'Guest',
                    'custom_fields' => [
                        [
                            'display_name' => 'Order Code',
                            'variable_name' => 'order_code',
                            'value' => $order->order_code,
                        ],
                    ],
                ],
            ]);

            if (!$result['success']) {
                Log::warning('paystack.initialize_failed', [
                    'order_id' => $order->id,
                    'message' => $result['message'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

            // Create pending transaction
            $paymentMethod = PaymentMethod::where('code', 'paystack')->first();

            $transaction = Transaction::create([
                'order_id' => $order->id,
                'payment_method_id' => $paymentMethod?->id,
                'reference' => $reference,
                'amount' => $order->total,
                'currency' => 'NGN',
                'status' => 'pending',
                'metadata' => [
                    'authorization_url' => $result['data']['authorization_url'],
                    'access_code' => $result['data']['access_code'],
                ],
            ]);

            Log::info('paystack.transaction_created', [
                'transaction_id' => $transaction->id,
                'reference' => $reference,
                'order_id' => $order->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment initialized successfully',
                'data' => [
                    'authorization_url' => $result['data']['authorization_url'],
                    'access_code' => $result['data']['access_code'],
                    'reference' => $reference,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('paystack.initialize.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while initializing payment',
            ], 500);
        }
    }

    /**
     * Handle payment callback with double verification
     */
    public function callback(Request $request)
    {
        $reference = $request->query('reference');

        if (!$reference) {
            return redirect()->route('home.index')->with('error', 'Invalid payment reference');
        }

        try {
            // Find transaction
            $transaction = Transaction::where('reference', $reference)->first();

            if (!$transaction) {
                Log::warning('paystack.callback.transaction_not_found', [
                    'reference' => $reference,
                ]);

                return redirect()->route('home.index')->with('error', 'Transaction not found');
            }

            // If already verified, redirect
            if ($transaction->status === 'completed' || $transaction->status->value === 'completed') {
                return redirect()->route('order.success', ['reference' => $reference])
                    ->with('success', 'Payment already verified');
            }

            DB::beginTransaction();

            try {
                // DOUBLE VERIFICATION PROCESS
                Log::info('paystack.double_verification.started', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                ]);

                // Step 1: First verification
                $firstVerification = $this->paystack->verifyPayment($reference);

                if (!$firstVerification['success']) {
                    Log::warning('paystack.first_verification_failed', [
                        'reference' => $reference,
                        'message' => $firstVerification['message'],
                    ]);

                    $transaction->update([
                        'status' => 'cancelled',
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'first_verification_failed' => true,
                            'error_message' => $firstVerification['message'],
                        ]),
                    ]);

                    DB::commit();

                    return redirect()->route('order.failed', ['reference' => $reference])
                        ->with('error', 'Payment verification failed');
                }

                // Step 2: Second verification (Double check)
                $secondVerification = $this->paystack->doubleVerifyPayment($reference);

                if (!$secondVerification['success']) {
                    Log::warning('paystack.second_verification_failed', [
                        'reference' => $reference,
                        'message' => $secondVerification['message'],
                    ]);

                    $transaction->update([
                        'status' => 'cancelled',
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'first_verification' => 'passed',
                            'second_verification_failed' => true,
                            'error_message' => $secondVerification['message'],
                        ]),
                    ]);

                    DB::commit();

                    return redirect()->route('order.failed', ['reference' => $reference])
                        ->with('error', 'Payment double verification failed');
                }

                // Both verifications passed - check payment status
                $paymentData = $secondVerification['data'];

                if ($paymentData['status'] !== 'success') {
                    Log::warning('paystack.payment_not_successful', [
                        'reference' => $reference,
                        'status' => $paymentData['status'],
                    ]);

                    $transaction->update([
                        'status' => 'cancelled',
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'paystack_status' => $paymentData['status'],
                            'payment_data' => $paymentData,
                        ]),
                    ]);

                    DB::commit();

                    return redirect()->route('order.failed', ['reference' => $reference])
                        ->with('error', 'Payment was not successful');
                }

                // Payment successful - update transaction
                $transaction->update([
                    'status' => 'completed',
                    'paid_at' => now(),
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'double_verified' => true,
                        'first_verification_status' => 'passed',
                        'second_verification_status' => 'passed',
                        'paystack_data' => $paymentData,
                        'channel' => $paymentData['channel'] ?? null,
                        'ip_address' => $paymentData['ip_address'] ?? null,
                    ]),
                ]);

                // Update order
                $order = $transaction->order;
                $order->update([
                    'payment_status' => 'paid',
                    'payment_method' => 'Paystack',
                ]);

                Log::info('paystack.payment_successful', [
                    'reference' => $reference,
                    'transaction_id' => $transaction->id,
                    'order_id' => $order->id,
                    'amount' => $transaction->amount,
                ]);

                // Send email notifications
                try {
                    // Send order confirmation to customer
                    Mail::to($order->customer->email)->send(new OrderReceivedMail($order));
                    
                    // Send new order notification to admin
                    $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL', 'admin@example.com'));
                    if ($adminEmail && $adminEmail !== 'admin@example.com') {
                        Mail::to($adminEmail)->send(new NewOrderAdminMail($order));
                    }

                    $vendorEmail = $order->vendor?->email;
                    if ($vendorEmail) {
                        Mail::to($vendorEmail)->send(new VendorOrderNotificationMail($order));
                    }
                    
                    Log::info('paystack.callback.emails_sent', [
                        'order_id' => $order->id,
                        'customer_email' => $order->customer->email,
                    ]);
                } catch (\Exception $e) {
                    Log::error('paystack.callback.email_failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                }

                DB::commit();

                return redirect()->route('order.success', ['reference' => $reference])
                    ->with('success', 'Payment verified successfully');
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error('paystack.callback.error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('home.index')
                ->with('error', 'An error occurred while processing your payment');
        }
    }

    /**
     * Handle Paystack webhook
     */
    public function webhook(Request $request)
    {
        // Verify webhook signature
        $signature = $request->header('x-paystack-signature');
        $payload = $request->getContent();

        if (!$this->paystack->verifyWebhookSignature($payload, $signature)) {
            Log::warning('paystack.webhook.invalid_signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        Log::info('paystack.webhook.received', [
            'event' => $event,
            'reference' => $data['reference'] ?? null,
        ]);

        try {
            switch ($event) {
                case 'charge.success':
                    $this->handleChargeSuccess($data);
                    break;

                case 'charge.failed':
                    $this->handleChargeFailed($data);
                    break;

                default:
                    Log::info('paystack.webhook.unhandled_event', ['event' => $event]);
            }

            return response()->json(['message' => 'Webhook processed'], 200);
        } catch (\Throwable $e) {
            Log::error('paystack.webhook.error', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Error processing webhook'], 500);
        }
    }

    /**
     * Handle successful charge webhook
     */
    protected function handleChargeSuccess(array $data)
    {
        $reference = $data['reference'] ?? null;

        if (!$reference) {
            return;
        }

        $transaction = Transaction::where('reference', $reference)->first();

        $transactionStatus = $transaction->status instanceof \App\Enums\TransactionStatus 
            ? $transaction->status->value 
            : $transaction->status;

        if (!$transaction || $transactionStatus === 'completed') {
            return;
        }

        // Double verify even from webhook
        $verification = $this->paystack->doubleVerifyPayment($reference);

        if ($verification['success'] && $verification['data']['status'] === 'success') {
            DB::transaction(function () use ($transaction, $data) {
                $transaction->update([
                    'status' => 'completed',
                    'paid_at' => now(),
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'webhook_received' => true,
                        'webhook_data' => $data,
                    ]),
                ]);

                $transaction->order->update([
                    'payment_status' => 'paid',
                    'payment_method' => 'Paystack',
                ]);

                Log::info('paystack.webhook.payment_updated', [
                    'reference' => $transaction->reference,
                    'transaction_id' => $transaction->id,
                ]);

                // Send email notifications
                try {
                    // Send order confirmation to customer
                    Mail::to($transaction->order->customer->email)->send(new OrderReceivedMail($transaction->order));
                    
                    // Send new order notification to admin
                    $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL', 'admin@example.com'));
                    if ($adminEmail && $adminEmail !== 'admin@example.com') {
                        Mail::to($adminEmail)->send(new NewOrderAdminMail($transaction->order));
                    }

                    $vendorEmail = $transaction->order->vendor?->email;
                    if ($vendorEmail) {
                        Mail::to($vendorEmail)->send(new VendorOrderNotificationMail($transaction->order));
                    }
                    
                    Log::info('paystack.webhook.emails_sent', [
                        'order_id' => $transaction->order->id,
                        'customer_email' => $transaction->order->customer->email,
                    ]);
                } catch (\Exception $e) {
                    Log::error('paystack.webhook.email_failed', [
                        'order_id' => $transaction->order->id,
                        'error' => $e->getMessage()
                    ]);
                }
            });
        }
    }

    /**
     * Handle failed charge webhook
     */
    protected function handleChargeFailed(array $data)
    {
        $reference = $data['reference'] ?? null;

        if (!$reference) {
            return;
        }

        $transaction = Transaction::where('reference', $reference)->first();

        $transactionStatus = $transaction->status instanceof \App\Enums\TransactionStatus 
            ? $transaction->status->value 
            : $transaction->status;

        if (!$transaction || $transactionStatus === 'completed') {
            return;
        }

        $transaction->update([
            'status' => 'cancelled',
            'metadata' => array_merge($transaction->metadata ?? [], [
                'webhook_failed' => true,
                'webhook_data' => $data,
            ]),
        ]);

        Log::info('paystack.webhook.payment_failed', [
            'reference' => $reference,
            'transaction_id' => $transaction->id,
        ]);
    }
}
