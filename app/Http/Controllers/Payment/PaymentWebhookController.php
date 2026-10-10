<?php

namespace App\Http\Controllers\Payment;

use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Store;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\Data\WebhookScope;
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
 * ## Two URLs, and why both exist
 *
 * `POST /webhooks/payments/{provider}` names only the provider. A webhook
 * arrives from a provider, not from a business: the body says nothing about
 * which account it belongs to, so the only way to establish that is to try each
 * active connection's secret until one verifies the signature — which is exactly
 * what the Paystack-only controller did before this, and the reason it had to
 * read every business's key. That URL is registered in live provider dashboards
 * and stays working, unchanged, for businesses that pasted it.
 *
 * `POST /webhooks/payments/{provider}/{scope}` names whose connection it is, and
 * is the URL a business is given to paste into its provider's dashboard. It
 * narrows the candidate list from every connection on the platform to that one
 * connection, and it is what stops a verified signature from being able to
 * settle a transaction belonging to somebody else.
 *
 * Either way the candidate list is scoped to **this provider** and nothing else:
 * a Flutterwave webhook is never checked against a Paystack secret. The first
 * driver call that returns an event wins, and it is that connection's
 * credentials the settlement then re-verifies with.
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

    public function handle(Request $request, string $provider = 'paystack', ?string $scope = null): JsonResponse
    {
        $driver = $this->manager->driver($provider);

        if ($driver === null) {
            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        $named = $scope === null ? null : $this->scopeFromCode((string) $scope, $provider);

        // A scope we cannot resolve is answered exactly like a signature we
        // cannot verify, on purpose. Returning 404 here would turn this endpoint
        // into an oracle: a stranger could walk store and business codes and
        // learn which ones exist.
        if ($scope !== null && $named === null) {
            return $this->unverified($request, $provider);
        }

        $webhook = new WebhookRequest($request->getContent(), $this->headers($request));

        foreach ($this->candidateCredentials($provider, $named) as $credentials) {
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

            $result = $this->processor->settle($event, $driver, $credentials, $named);

            return response()->json(['status' => $result['status']], $result['http']);
        }

        return $this->unverified($request, $provider);
    }

    /**
     * The one answer given to anything this endpoint will not act on.
     *
     * Kept in a method so an unresolvable scope and an unverifiable signature
     * cannot drift apart into two distinguishable responses.
     */
    private function unverified(Request $request, string $provider): JsonResponse
    {
        Log::warning('payments.webhook.unverified_signature', [
            'provider' => $provider,
            'ip' => $request->ip(),
        ]);

        return response()->json(['status' => 'unverified'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Resolve the URL's `{scope}` segment.
     *
     * Resolved by hand rather than by route-model binding, for two reasons: the
     * segment may be a store or a business, so no single model binds it; and
     * binding would 404 before the controller, which is the leaking response
     * described above. Null means "no such scope", which the caller turns into
     * the same answer a bad signature gets.
     *
     * Deleted and closed stores are treated differently on purpose. A store row
     * that is gone resolves to nothing; one that is merely closed still settles,
     * because money moved before the closure and refusing it would strand a real
     * payment with no record of why.
     */
    private function scopeFromCode(string $code, string $provider): ?WebhookScope
    {
        $store = Store::query()->where('store_id', $code)->first();

        if ($store !== null) {
            return new WebhookScope(
                (int) $store->business_id,
                (int) $store->id,
                $this->storeHasOwnKeys($provider, (int) $store->id),
            );
        }

        $business = Business::query()->where('business_code', $code)->first();

        return $business === null ? null : new WebhookScope((int) $business->id);
    }

    /**
     * Whether this store charged with its own provider credentials.
     *
     * Deliberately does **not** filter on `is_active`, unlike the platform-wide
     * scan: what matters here is which keys the payment was actually taken with.
     * {@see PaymentGatewayResolver::resolve()} picks the store's config whenever
     * the row carries one, regardless of enablement — so a signature that
     * verified against those keys proves this is that store's own account, even
     * if the row has since been switched off.
     */
    private function storeHasOwnKeys(string $provider, int $storeId): bool
    {
        $methodId = $this->methodId($provider);

        if ($methodId === null) {
            return false;
        }

        return ! empty(
            DB::table('store_payment_method')
                ->where('store_id', $storeId)
                ->where('payment_method_id', $methodId)
                ->value('config')
        );
    }

    /**
     * The connections whose secrets may be tried against this signature.
     *
     * Unscoped, that is every active connection for this provider, business-wide
     * and per store — the behaviour the URL without a scope has always had.
     * Scoped, it is the single connection the URL named, which is the point of
     * the scoped URL: one decryption instead of the whole table, and no
     * possibility of a signature verifying against an unrelated business.
     *
     * @return array<int, GatewayCredentials>
     */
    private function candidateCredentials(string $provider, ?WebhookScope $scope): array
    {
        $methodId = $this->methodId($provider);

        if ($methodId === null) {
            return [];
        }

        $secrets = PaymentGatewayRegistry::secretKeysFor($provider);

        if ($scope !== null) {
            $config = $this->scopedConfig($methodId, $scope);

            if ($config === null) {
                return [];
            }

            return $this->credentialsFrom($provider, $config, $secrets);
        }

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
            ->map(fn ($config): array => $this->credentialsFrom($provider, $config, $secrets))
            ->flatten(1)
            ->values()
            ->all();
    }

    /**
     * The one config a scoped URL resolves to: the store's own keys when it has
     * them, otherwise the business's shared ones.
     *
     * This mirrors {@see PaymentGatewayResolver::resolve()}'s precedence exactly.
     * Anywhere the two disagree, a payment would be charged with one set of keys
     * and its webhook checked against another, and it would fail with nothing in
     * the logs to say why.
     */
    private function scopedConfig(int $methodId, WebhookScope $scope): mixed
    {
        if ($scope->storeId !== null && $scope->storeOwnsKeys) {
            return DB::table('store_payment_method')
                ->where('store_id', $scope->storeId)
                ->where('payment_method_id', $methodId)
                ->value('config');
        }

        return DB::table('business_payment_method')
            ->where('business_id', $scope->businessId)
            ->where('payment_method_id', $methodId)
            ->value('config');
    }

    /**
     * @param  array<int, string>  $secrets
     * @return array<int, GatewayCredentials>
     */
    private function credentialsFrom(string $provider, mixed $config, array $secrets): array
    {
        $decoded = is_string($config) ? json_decode($config, true) : (array) $config;

        $credentials = GatewayCredentials::make(
            $provider,
            CredentialCipher::decrypt(is_array($decoded) ? $decoded : [], $secrets),
            $secrets,
        );

        return $credentials->isEmpty() ? [] : [$credentials];
    }

    private function methodId(string $provider): ?int
    {
        $id = DB::table('payment_methods')->where('code', $provider)->value('id');

        return $id === null ? null : (int) $id;
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
