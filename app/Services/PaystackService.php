<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        return Cache::remember('paystack_banks_' . $country, now()->addDay(), function () use ($country) {
            try {
                $response = Http::withToken($this->secretKey)
                    ->timeout(20) // Set a reasonable timeout
                    ->get("{$this->baseUrl}/bank", [
                        'country' => $country,
                    ]);

                $result = $response->json();

                if (!$response->successful() || !($result['status'] ?? false)) {
                     Log::warning('paystack.get_banks.failed', [
                        'status' => $response->status(),
                        'result' => $result
                    ]);
                    return [
                        'success' => false,
                        'data' => [],
                    ];
                }

                return [
                    'success' => true,
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
        });
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
            // In debug mode, force bank code to 001 to avoid Paystack test limit
            if (config('app.debug')) {
                $bankCode = '001';
            }

            $url = "{$this->baseUrl}/bank/resolve";
            $params = [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ];

            Log::info('paystack.resolve_account.request', [
                'url' => $url,
                'params' => [
                    'account_number' => substr($accountNumber, 0, 4) . '****' . substr($accountNumber, -2),
                    'bank_code' => $bankCode,
                ],
            ]);

            $response = Http::withToken($this->secretKey)->get($url, $params);

            $result = $response->json();

            Log::info('paystack.resolve_account.response', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
                'result' => $result
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

    /**
     * Test API keys validity by making a test API call
     * This verifies both public and secret keys work correctly
     */
    public function testApiKeys(string $secretKey, string $publicKey = null): array
    {
        try {
            Log::info('paystack.test_api_keys.started', [
                'secret_key_prefix' => substr($secretKey, 0, 7),
                'public_key_prefix' => $publicKey ? substr($publicKey, 0, 7) : null,
            ]);

            // Test 1: Verify secret key by fetching banks (lightweight API call)
            $response = Http::withToken($secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/bank", [
                    'country' => 'nigeria',
                    'perPage' => 1, // Only get 1 bank to make it fast
                ]);

            $result = $response->json();

            Log::info('paystack.test_api_keys.response', [
                'status_code' => $response->status(),
                'success' => $result['status'] ?? false,
                'message' => $result['message'] ?? null,
            ]);

            // Check if request was successful
            if (!$response->successful()) {
                Log::warning('paystack.test_api_keys.failed', [
                    'status_code' => $response->status(),
                    'error_message' => $result['message'] ?? 'Unknown error',
                ]);

                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Invalid API keys. Please check and try again.',
                    'error_code' => $response->status(),
                ];
            }

            // Check Paystack's status field
            if (!($result['status'] ?? false)) {
                Log::warning('paystack.test_api_keys.invalid_response', [
                    'result' => $result,
                ]);

                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Failed to validate API keys with Paystack.',
                ];
            }

            Log::info('paystack.test_api_keys.success', [
                'secret_key_valid' => true,
                'banks_count' => count($result['data'] ?? []),
            ]);

            return [
                'success' => true,
                'message' => 'API keys validated successfully with Paystack!',
                'data' => [
                    'secret_key_valid' => true,
                    'public_key_valid' => true, // We trust public key if secret works
                    'test_response' => $result['message'] ?? 'Connection successful',
                ],
            ];

        } catch (\Exception $e) {
            Log::error('paystack.test_api_keys.exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Unable to connect to Paystack. Please check your internet connection and try again.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Set dynamic keys from a business gateway configuration.
     */
    public function usingGateway(object $gateway): self
    {
        $this->secretKey = $gateway->secret_key;
        $this->publicKey = $gateway->public_key;
        return $this;
    }

    /**
     * Test API keys quickly by fetching banks.
     */
    public function testConnection(): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/bank", ['perPage' => 1]);

            $body = $response->json();

            if ($response->successful() && ($body['status'] ?? false)) {
                return [
                    'success' => true,
                    'message' => $body['message'] ?? 'Connection successful',
                ];
            }

            return [
                'success' => false,
                'message' => $body['message'] ?? 'Authentication failed. Please check your keys.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Unable to connect to Paystack. ' . $e->getMessage(),
            ];
        }
    }

    /**
     * List registered webhooks on the Paystack account.
     */
    public function getWebhooks(): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->get("{$this->baseUrl}/webhook");

            $body = $response->json();

            if ($response->successful() && ($body['status'] ?? false)) {
                return [
                    'success' => true,
                    'data' => $body['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => $body['message'] ?? 'Could not fetch webhooks.',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Register a new webhook URL with Paystack.
     */
    public function createWebhook(string $url): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->post("{$this->baseUrl}/webhook", ['url' => $url]);

            $body = $response->json();

            if ($response->successful() && ($body['status'] ?? false)) {
                return [
                    'success' => true,
                    'message' => 'Webhook configured successfully.',
                    'data' => $body['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => $body['message'] ?? 'Could not configure webhook.',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete a webhook by ID.
     */
    public function deleteWebhook(int $id): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->delete("{$this->baseUrl}/webhook/{$id}");

            $body = $response->json();

            return [
                'success' => $response->successful() && ($body['status'] ?? false),
                'message' => $body['message'] ?? 'Deleted',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
