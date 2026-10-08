<?php

namespace App\Services\Payments\Gateways;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\ConnectionResult;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\InitializationResult;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\RefundResult;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;

/**
 * Bank transfer, as a driver.
 *
 * This exists to prove the interface does not secretly assume an HTTP gateway.
 * There is no provider to call: the customer is shown where to send money, and
 * a person on the management side confirms it. So `initialize()` returns
 * OFFLINE with instructions rather than a redirect URL, and `verify()` returns
 * PENDING — which is the honest answer, not a placeholder. Collapsing it into
 * FAILED would report a transfer that is simply still waiting as rejected.
 *
 * The bank accounts arrive through `metadata['bank_accounts']`, resolved by the
 * caller, so this class never touches the database. Each entry is
 * `['bank_name' => ..., 'account_number' => ..., 'account_name' => ...]`.
 */
final class ManualTransferGateway implements PaymentGateway
{
    public function code(): string
    {
        return 'bank_transfer';
    }

    public function currency(): string
    {
        return 'NGN';
    }

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        /** @var array<int, array<string, string>> $accounts */
        $accounts = is_array($intent->metadata['bank_accounts'] ?? null)
            ? $intent->metadata['bank_accounts']
            : [];

        $instructions = [];

        foreach ($accounts as $account) {
            $instructions[] = [
                'label' => (string) ($account['bank_name'] ?? 'Bank'),
                'value' => (string) ($account['account_number'] ?? ''),
                'secondary' => (string) ($account['account_name'] ?? ''),
            ];
        }

        if ($instructions === []) {
            return InitializationResult::failed(
                'This store has not set up a bank account for transfers yet.'
            );
        }

        return InitializationResult::offline(
            instructions: $instructions,
            providerReference: $intent->reference,
            message: 'Transfer the amount and use your order number as the reference.',
        );
    }

    /**
     * Always pending. Settlement is confirmed by a person, so there is nothing
     * to poll and nothing that could legitimately report success from here.
     */
    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        return VerificationResult::pending('Bank transfers are confirmed by the store once the money arrives.');
    }

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        return RefundResult::failed('Bank transfers are refunded outside the platform.');
    }

    /** No provider, no webhook. Null is the correct permanent answer. */
    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        return null;
    }

    /**
     * Nothing to authenticate against — the check is whether a bank account
     * exists to transfer to at all, which the caller passes in.
     */
    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        if ($credentials->has('has_bank_account')) {
            return ConnectionResult::ok('Bank account on file.');
        }

        return ConnectionResult::failed('Add a bank account before enabling bank transfers.');
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function supportsPartialPayment(): bool
    {
        return true;
    }
}
