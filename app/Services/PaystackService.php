<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackService
{
    protected string $secretKey;
    protected string $publicKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->publicKey = config('services.paystack.public_key');
        $this->baseUrl = config('services.paystack.base_url');
    }

    /**
     * Initialize a payment transaction
     */
    public function initializePayment(array $data): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/transaction/initialize", [
                    'email' => $data['email'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'NGN',
                    'reference' => $data['reference'] ?? $this->generateReference(),
                    'callback_url' => $data['callback_url'] ?? route('payment.paystack.callback'),
                    'metadata' => $data['metadata'] ?? [],
                    'channels' => $data['channels'] ?? ['card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'],
                ]);

            $result = $response->json();

            Log::info('paystack.initialize', [
                'status' => $response->status(),
                'success' => $result['status'] ?? false,
                'reference' => $data['reference'] ?? null,
            ]);

            if (!$response->successful() || !($result['status'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to initialize payment',
                    'data' => null,
                ];
            }

            return [
                'success' => true,
                'message' => 'Payment initialized successfully',
                'data' => $result['data'],
            ];
        } catch (\Throwable $e) {
            Log::error('paystack.initialize.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while initializing payment',
                'data' => null,
            ];
        }
    }

    /**
     * Verify a payment transaction (First Confirmation)
     */
    public function verifyPayment(string $reference): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/transaction/verify/{$reference}");

            $result = $response->json();

            Log::info('paystack.verify', [
                'reference' => $reference,
                'status' => $response->status(),
                'success' => $result['status'] ?? false,
            ]);

            if (!$response->successful() || !($result['status'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Payment verification failed',
                    'data' => null,
                ];
            }

            return [
                'success' => true,
                'message' => 'Payment verified successfully',
                'data' => $result['data'],
            ];
        } catch (\Throwable $e) {
            Log::error('paystack.verify.error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while verifying payment',
                'data' => null,
            ];
        }
    }

    /**
     * Double verify payment (Second Confirmation via different endpoint)
     */
    public function doubleVerifyPayment(string $reference): array
    {
        try {
            // First verification
            $firstVerify = $this->verifyPayment($reference);

            if (!$firstVerify['success']) {
                return $firstVerify;
            }

            // Second verification using transaction endpoint
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/transaction", [
                    'reference' => $reference,
                ]);

            $result = $response->json();

            Log::info('paystack.double_verify', [
                'reference' => $reference,
                'status' => $response->status(),
                'success' => $result['status'] ?? false,
                'first_verify_status' => $firstVerify['data']['status'] ?? null,
            ]);

            if (!$response->successful() || !($result['status'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Second verification failed',
                    'data' => null,
                ];
            }

            // Get the transaction from the data array
            $transactions = $result['data'] ?? [];
            $transaction = null;

            foreach ($transactions as $txn) {
                if (($txn['reference'] ?? null) === $reference) {
                    $transaction = $txn;
                    break;
                }
            }

            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found in second verification',
                    'data' => null,
                ];
            }

            // Compare both verifications
            $firstStatus = $firstVerify['data']['status'] ?? null;
            $secondStatus = $transaction['status'] ?? null;

            if ($firstStatus !== $secondStatus) {
                Log::warning('paystack.verification_mismatch', [
                    'reference' => $reference,
                    'first_status' => $firstStatus,
                    'second_status' => $secondStatus,
                ]);

                return [
                    'success' => false,
                    'message' => 'Verification status mismatch',
                    'data' => null,
                ];
            }

            // Both verifications match
            return [
                'success' => true,
                'message' => 'Payment double verified successfully',
                'data' => $firstVerify['data'], // Use first verification data as it's more detailed
                'second_verification' => $transaction,
            ];
        } catch (\Throwable $e) {
            Log::error('paystack.double_verify.error', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred during double verification',
                'data' => null,
            ];
        }
    }

    /**
     * Get all supported banks
     */
    public function getBanks(string $country = 'nigeria'): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/bank", [
                    'country' => $country,
                ]);

            $result = $response->json();

            return [
                'success' => $result['status'] ?? false,
                'data' => $result['data'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('paystack.get_banks.error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => [],
            ];
        }
    }

    /**
     * Generate a unique payment reference
     */
    public function generateReference(string $prefix = 'PST'): string
    {
        return $prefix . '_' . time() . '_' . strtoupper(substr(md5(uniqid()), 0, 8));
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $hash = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($hash, $signature);
    }

    /**
     * Get public key for frontend
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Resolve/validate a bank account number
     */
    public function resolveAccountNumber(string $accountNumber, string $bankCode): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/bank/resolve", [
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                ]);

            $result = $response->json();

            Log::info('paystack.resolve_account', [
                'account_number' => substr($accountNumber, 0, 4) . '****' . substr($accountNumber, -2),
                'bank_code' => $bankCode,
                'status' => $response->status(),
                'success' => $result['status'] ?? false,
            ]);

            if (!$response->successful() || !($result['status'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Could not verify account number',
                    'data' => null,
                ];
            }

            return [
                'success' => true,
                'message' => 'Account verified successfully',
                'data' => [
                    'account_number' => $result['data']['account_number'] ?? $accountNumber,
                    'account_name' => $result['data']['account_name'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('paystack.resolve_account.error', [
                'account_number' => substr($accountNumber, 0, 4) . '****',
                'bank_code' => $bankCode,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while verifying account',
                'data' => null,
            ];
        }
    }
}
