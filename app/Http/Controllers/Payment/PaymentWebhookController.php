<?php

namespace App\Http\Controllers\Payment;

use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One webhook endpoint for every payment provider.
 *
 * ## Why the credentials are tried in turn
 *
 * A webhook arrives from a provider, not from a business: the body says nothing
 * about which account it belongs to. For the signature-signed providers the
 * only way to establish that is to try each active connection's secret until
 * one verifies the signature — which is exactly what the Paystack-only
 * controller did before this, and the reason it had to read every business's
 * key.
 *
 * So the candidate list is scoped to **this provider's** connections and
 * nothing else: a Flutterwave webhook is never checked against a Paystack
 * secret. The first driver call that returns an event wins, and it is that
 * connection's credentials the settlement then re-verifies with.
 *
 * `POST /webhooks/paystack` is kept as an alias, because the URL is registered
 * in Paystack's own dashboard and changing it would silently stop settlement
 * until someone remembered to update it there.
 */
final class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $manager,
        private readonly PaymentWebhookProcessor $processor,
    ) {}

    public function handle(Request $request, string $provider = 'paystack'): JsonResponse
    {
        $driver = $this->manager->driver($provider);

        if ($driver === null) {
            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        $webhook = new WebhookRequest($request->getContent(), $this->headers($request));

        foreach ($this->candidateCredentials($provider) as $credentials) {
            $event = $driver->parseWebhook($webhook, $credentials);

            // Null means "this signature is not mine" — try the next connection.
            if ($event === null) {
                continue;
            }

            if ($event->type === WebhookEventType::IGNORED) {
                return response()->json(['status' => 'unhandled event']);
            }

            if (! $event->isSettlement()) {
                // Understood and verified, but not something that moves money
                // here. Answered 200 so the provider stops retrying it.
                return response()->json(['status' => 'noted']);
            }

            $result = $this->processor->settle($event, $driver, $credentials);

            return response()->json(['status' => $result['status']], $result['http']);
        }

        Log::warning('payments.webhook.unverified_signature', [
            'provider' => $provider,
            'ip' => $request->ip(),
        ]);

        return response()->json(['status' => 'unverified'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Every active connection for this provider, business-wide and per store.
     *
     * @return array<int, GatewayCredentials>
     */
    private function candidateCredentials(string $provider): array
    {
        $methodId = DB::table('payment_methods')->where('code', $provider)->value('id');

        if ($methodId === null) {
            return [];
        }

        $secrets = PaymentGatewayRegistry::secretKeysFor($provider);

        $rows = DB::table('business_payment_method')
            ->where('payment_method_id', $methodId)
            ->where('is_active', true)
            ->whereNotNull('config')
            ->pluck('config')
            ->merge(
                // A store that stored its own keys is a separate candidate; one
                // that did not is already covered by its business row.
                DB::table('store_payment_method')
                    ->where('payment_method_id', $methodId)
                    ->where('is_active', true)
                    ->whereNotNull('config')
                    ->pluck('config')
            );

        return $rows
            ->map(function ($config) use ($provider, $secrets): GatewayCredentials {
                $decoded = is_string($config) ? json_decode($config, true) : (array) $config;

                return GatewayCredentials::make(
                    $provider,
                    CredentialCipher::decrypt(is_array($decoded) ? $decoded : [], $secrets),
                    $secrets,
                );
            })
            ->filter(fn (GatewayCredentials $credentials): bool => ! $credentials->isEmpty())
            ->values()
            ->all();
    }

    /**
     * Headers with lower-cased names, because providers disagree on casing —
     * `X-Paystack-Signature` and `x-paystack-signature` both occur.
     *
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $headers;
    }
}
