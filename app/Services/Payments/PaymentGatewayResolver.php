<?php

namespace App\Services\Payments;

use App\Models\Store;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\ResolvedGateway;
use App\Services\Plugins\PluginResolver;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\PaymentWebhookUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which payment providers a store may use, and with whose keys.
 *
 * The payments twin of {@see PluginResolver}, and the
 * single place the precedence rule lives — the storefront, the POS, invoices
 * and the management screen must all agree, and they historically did not.
 *
 *     effective row for (store, provider) =
 *           store_payment_method row for (store, provider)   if it exists   ← wins
 *        ?? business_payment_method row for (business, provider)            ← default
 *
 *     usable by the store =
 *        payment_methods.is_active            (the platform offers it at all)
 *        AND an effective row exists AND effective.is_active
 *        AND the provider's currency matches the store's
 *        AND the credentials are complete
 *
 * Two things this fixes, both live defects:
 *
 * 1. The old storefront query fell back to **every active platform method**
 *    when a store had no assignment. A store could therefore be offered a
 *    gateway its business had never connected, and — because the key lookup was
 *    also broken — charged through the platform's account. An unconnected
 *    provider must resolve to *not offered*, never to "charged through us".
 *
 * 2. Credentials were read from `$gateway->pivot->api_keys`, a column that does
 *    not exist, so business keys were never used. They are read from `config`
 *    here, decrypted, with the store's own config winning when it has one.
 */
final class PaymentGatewayResolver
{
    private const DEFAULT_CURRENCY = 'NGN';

    /**
     * Every provider a store can actually take money with, keyed by code.
     *
     * @return array<string, ResolvedGateway>
     */
    public function forStore(Store $store): array
    {
        $business = $this->businessRows((int) $store->business_id);
        $storeRows = $this->storeRows((int) $store->id);
        $currency = $this->currencyFor($store);

        $usable = [];

        foreach (PaymentGatewayRegistry::keys() as $code) {
            $resolved = $this->resolve($code, $business->get($code), $storeRows->get($code));

            if (! $resolved->isEnabled) {
                continue;
            }

            // A provider settling in USD cannot serve a store charging NGN.
            // Filtering here is why nothing anywhere needs `if ($code === 'bitfra')`.
            if (! $this->currencyMatches($code, $currency)) {
                continue;
            }

            if (! $this->isProvisioned($code, $resolved, (int) $store->business_id)) {
                continue;
            }

            $usable[$code] = $resolved;
        }

        return $usable;
    }

    /**
     * One provider's resolved state, or null when the provider is unknown.
     */
    public function connection(int $businessId, string $code, ?int $storeId = null): ?ResolvedGateway
    {
        if (! PaymentGatewayRegistry::has($code)) {
            return null;
        }

        return $this->resolve(
            $code,
            $this->businessRows($businessId)->get($code),
            $storeId ? $this->storeRows($storeId)->get($code) : null,
        );
    }

    /**
     * Every provider's state for the management screen, including the ones that
     * are off — the screen renders a card for each.
     *
     * @return array<int, array<string, mixed>>
     */
    public function states(int $businessId, ?Store $store = null): array
    {
        $business = $this->businessRows($businessId);
        $storeRows = $store ? $this->storeRows((int) $store->id) : collect();
        $currency = $store ? $this->currencyFor($store) : null;
        // Resolved once rather than per provider — it cannot change inside the
        // loop, and it is a query.
        $businessCode = DB::table('businesses')->where('id', $businessId)->value('business_code');

        $states = [];

        foreach (PaymentGatewayRegistry::keys() as $code) {
            $resolved = $this->resolve($code, $business->get($code), $storeRows->get($code));
            $required = PaymentGatewayRegistry::requiredKeysFor($code);

            $method = DB::table('payment_methods')->where('code', $code)->first();

            $states[] = [
                'code' => $code,
                'is_enabled' => $resolved->isEnabled,
                // Whether the platform offers this provider at all.
                //
                // False in two distinct cases, and the screen treats them the
                // same way for the same reason — neither can be connected:
                //   * no `payment_methods` row, i.e. no driver has been built;
                //   * a row exists but an admin has switched it off.
                //
                // This is the switch the admin console's toggle drives, so
                // honouring it here is what makes that control mean anything on
                // the business's own screen.
                'available' => $method !== null && (bool) $method->is_active,
                // Distinguished from the above so a business that already has
                // this connected is told why it stopped working rather than
                // just finding the card gone.
                'disabled_by_platform' => $method !== null && ! $method->is_active,
                // A connection the business made, whether or not the platform
                // currently offers it. The screen keys off this so a provider
                // switched off by an admin stays visible, with the reason.
                'has_connection' => $resolved->hasActiveConnection,
                'source' => $resolved->source,
                'connection_id' => $resolved->connectionId,
                'business_connected' => $resolved->businessConnected,
                'store_overrides' => $resolved->storeOverrides,
                'has_credentials' => $resolved->credentials->isComplete($required),
                // Masked values only — the screen shows which key is stored,
                // never the key. Secrets never leave the server in a response.
                'masked' => $resolved->credentials->masked(),
                // The URL this business pastes into the provider's dashboard.
                //
                // Present whether or not the provider is connected, because the
                // order matters: some providers only issue the webhook secret
                // *after* you have saved a URL with them, so the merchant has to
                // be able to read this before they can fill in the form.
                'webhook_url' => $this->webhookUrl($code, $method, $store, $businessCode),
                'unavailable_reason' => $this->unavailableReason($code, $resolved, $currency, $businessId),
            ];
        }

        return $states;
    }

    private function resolve(string $code, ?object $businessRow, ?object $storeRow): ResolvedGateway
    {
        $secrets = PaymentGatewayRegistry::secretKeysFor($code);
        $effective = $storeRow ?? $businessRow;

        // Enablement and credentials resolve independently: a store row decides
        // *whether*, and carries its own credentials only if it was given any.
        // A store row without config therefore inherits the business's keys —
        // which is what makes every existing plain-assignment row keep working.
        $configRow = ($storeRow !== null && ! empty($storeRow->config)) ? $storeRow : $businessRow;

        $credentials = GatewayCredentials::make(
            $code,
            $this->decrypt($configRow?->config, $secrets),
            $secrets,
        );

        return new ResolvedGateway(
            code: $code,
            isEnabled: $this->platformOffers($businessRow, $storeRow) && (bool) ($effective?->is_active ?? false),
            credentials: $credentials,
            source: $storeRow ? 'store' : ($businessRow ? 'business' : 'none'),
            connectionId: $effective?->id !== null ? (int) $effective->id : null,
            businessConnected: $businessRow !== null && (bool) $businessRow->is_active,
            storeOverrides: $storeRow !== null,
            // The row the business actually created, before the platform gate
            // is applied. This is what tells "an admin switched it off" apart
            // from "they never connected it".
            hasActiveConnection: $effective !== null && (bool) $effective->is_active,
        );
    }

    /**
     * The platform gate. A provider the platform has switched off is not
     * offered to anyone, however a business has configured it.
     */
    private function platformOffers(?object $businessRow, ?object $storeRow): bool
    {
        $row = $storeRow ?? $businessRow;

        return $row !== null && (bool) ($row->method_active ?? false);
    }

    /**
     * Whether the connection carries what it needs to take money.
     *
     * Not every provider is provisioned with keys. Manual bank transfer's
     * "credentials" are the business's own bank accounts, so it is complete
     * when one exists — checking `credentials->isComplete()` on it would mark
     * it permanently unfinished, because it has no fields to fill in.
     */
    private function isProvisioned(string $code, ResolvedGateway $resolved, ?int $businessId): bool
    {
        if (! $this->usesCredentials($code)) {
            return $businessId === null || $this->businessHasBankAccount($businessId);
        }

        return ! $resolved->credentials->isEmpty()
            && $resolved->credentials->isComplete(PaymentGatewayRegistry::requiredKeysFor($code));
    }

    private function usesCredentials(string $code): bool
    {
        return (PaymentGatewayRegistry::get($code)['credential_source'] ?? 'keys') === 'keys';
    }

    private function businessHasBankAccount(int $businessId): bool
    {
        return DB::table('store_banks')
            ->where('business_id', $businessId)
            ->where('is_verified', true)
            ->exists();
    }

    private function unavailableReason(string $code, ResolvedGateway $resolved, ?string $currency, int $businessId): ?string
    {
        $method = DB::table('payment_methods')->where('code', $code)->first();

        if ($method === null) {
            return 'Not available on this platform yet.';
        }

        if (! $method->is_active) {
            return 'Not enabled on this platform.';
        }

        if ($resolved->source === 'none') {
            return null;
        }

        if (! $this->usesCredentials($code) && ! $this->businessHasBankAccount($businessId)) {
            return 'Add a verified bank account before enabling bank transfers.';
        }

        if (! $resolved->credentials->isComplete(PaymentGatewayRegistry::requiredKeysFor($code))) {
            return 'Connected, but some credentials are missing.';
        }

        if ($currency !== null && ! $this->currencyMatches($code, $currency)) {
            return "Charges in {$this->providerCurrency($code)}, so it cannot be used by a store priced in {$currency}.";
        }

        return null;
    }

    /**
     * The webhook URL to show for this provider at this scope, or null when the
     * provider has no webhooks to receive.
     *
     * `bank_transfer` is settled by a human reading a bank slip, and a provider
     * with no `payment_methods` row has no driver to parse a payload — neither
     * has anything to paste anywhere.
     *
     * A store in scope gets that store's own URL, including when it charges
     * through the business's account: the URL still resolves to the right keys,
     * and providers that configure webhooks per store (Bitfra does) need one per
     * store. A business-wide scope gets the business's URL, which is what a
     * shared provider account has room for — one dashboard, one URL.
     */
    private function webhookUrl(string $code, ?object $method, ?Store $store, ?string $businessCode): ?string
    {
        if ($method === null || PaymentGatewayRegistry::checkoutModeFor($code) === 'offline') {
            return null;
        }

        if ($store !== null) {
            return PaymentWebhookUrl::forStore($code, $store);
        }

        return $businessCode === null ? null : PaymentWebhookUrl::forBusiness($code, $businessCode);
    }

    private function currencyMatches(string $code, string $currency): bool
    {
        return $this->providerCurrency($code) === $currency;
    }

    private function providerCurrency(string $code): string
    {
        return (string) (PaymentGatewayRegistry::get($code)['currency'] ?? self::DEFAULT_CURRENCY);
    }

    /**
     * A store prices in its own currency, falling back to the business's, then
     * to the platform default — the same order the rest of the app uses.
     */
    private function currencyFor(Store $store): string
    {
        $store->loadMissing(['currency', 'business']);

        return strtoupper(
            (string) ($store->currency?->code ?? $store->business?->currency ?? self::DEFAULT_CURRENCY)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decrypt(mixed $config, array $secretKeys): array
    {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        if (! is_array($config)) {
            return [];
        }

        return CredentialCipher::decrypt($config, $secretKeys);
    }

    /**
     * @return Collection<string, object>
     */
    private function businessRows(int $businessId): Collection
    {
        return DB::table('business_payment_method as bpm')
            ->join('payment_methods as pm', 'pm.id', '=', 'bpm.payment_method_id')
            ->where('bpm.business_id', $businessId)
            ->get([
                'pm.code',
                'pm.is_active as method_active',
                'bpm.id',
                'bpm.is_active',
                'bpm.config',
            ])
            ->keyBy('code');
    }

    /**
     * @return Collection<string, object>
     */
    private function storeRows(int $storeId): Collection
    {
        return DB::table('store_payment_method as spm')
            ->join('payment_methods as pm', 'pm.id', '=', 'spm.payment_method_id')
            ->where('spm.store_id', $storeId)
            ->get([
                'pm.code',
                'pm.is_active as method_active',
                'spm.id',
                'spm.is_active',
                'spm.config',
            ])
            ->keyBy('code');
    }
}
