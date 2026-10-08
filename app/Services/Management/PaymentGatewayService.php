<?php

namespace App\Services\Management;

use App\Models\Store;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Writes for the payment-provider screen.
 *
 * The shape deliberately mirrors `PluginService`: a business-wide row is the
 * default, a store row is the override, and deleting a store row falls the
 * store back to the default rather than to nothing.
 *
 * Two differences are worth naming.
 *
 * **Credentials are encrypted before they are stored**, and only the fields the
 * registry marks `secret`. That is the whole reason this service exists rather
 * than the controller writing the row directly — it is the one place that can
 * be trusted to run the cipher.
 *
 * **An update must not silently blank a stored secret.** The edit form cannot
 * show a secret back to the business, so it submits a placeholder or an empty
 * string; overwriting the stored value with that would lock them out of their
 * own gateway on the next deploy. Empty means "keep what is stored".
 */
final class PaymentGatewayService
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(
        string $provider,
        array $config,
        bool $isEnabled,
        User $actor,
        ?Store $store = null,
    ): void {
        $definition = PaymentGatewayRegistry::get($provider);
        $secretKeys = PaymentGatewayRegistry::secretKeysFor($provider);

        $attributes = $store
            ? ['store_id' => $store->id, 'payment_method_id' => $this->methodId($provider)]
            : ['business_id' => (int) $actor->business_id, 'payment_method_id' => $this->methodId($provider)];

        $model = $store ? 'store_payment_method' : 'business_payment_method';

        $existing = DB::table($model)->where($attributes)->first();
        $previous = $existing ? json_decode((string) $existing->config, true) : null;

        // Merged *before* validation, not after. The registry marks a secret
        // `required`, and a blank secret means "keep the stored one" — so
        // validating the raw payload first would reject every edit that did not
        // retype the key. Filling it in first means a genuinely changed secret
        // is still checked against its format.
        $payload = $this->mergeStoredSecrets($config, $previous, $secretKeys);
        $clean = PaymentGatewayRegistry::validatedConfig($provider, $payload);

        DB::transaction(function () use ($provider, $clean, $secretKeys, $isEnabled, $actor, $store, $definition, $attributes, $model, $existing): void {
            DB::table($model)->updateOrInsert(
                $attributes,
                [
                    'business_id' => (int) $actor->business_id,
                    'is_active' => $isEnabled,
                    'config' => json_encode(CredentialCipher::encrypt($clean, $secretKeys)),
                    'updated_at' => now(),
                    'created_at' => $existing?->created_at ?? now(),
                ],
            );

            ActivityRecorder::record(
                action: 'payment_gateway.connected',
                description: $definition['name'].' connected'.($store ? " for store {$store->name}" : ''),
                old: [],
                // Masked: the audit log must record *that* a key changed, never
                // the key. ActivityRecorder's own redaction catches `secret` by
                // name, but passing the ciphertext is belt and braces.
                new: ['is_enabled' => $isEnabled, 'config' => CredentialCipher::encrypt($clean, $secretKeys)],
                metadata: ['provider' => $provider, 'scope' => $store ? 'store' : 'business', 'store_id' => $store?->id],
                actor: $actor,
                businessId: (int) $actor->business_id,
            );
        });
    }

    /**
     * Remove a connection entirely.
     *
     * For a store this deletes the override, so the store returns to the
     * business default — which is a different outcome from switching it off,
     * and the screen distinguishes them.
     */
    public function disconnect(string $provider, User $actor, ?Store $store = null): void
    {
        $definition = PaymentGatewayRegistry::get($provider);

        DB::transaction(function () use ($provider, $actor, $store, $definition): void {
            $model = $store ? 'store_payment_method' : 'business_payment_method';

            $query = $store
                ? DB::table($model)->where('store_id', $store->id)
                : DB::table($model)->where('business_id', (int) $actor->business_id);

            $existing = $query->where('payment_method_id', $this->methodId($provider))->first();

            if ($existing === null) {
                return;
            }

            DB::table($model)->where('id', $existing->id)->delete();

            ActivityRecorder::record(
                action: 'payment_gateway.disconnected',
                description: $definition['name'].' disconnected'.($store ? " for store {$store->name}" : ''),
                new: [],
                metadata: ['provider' => $provider, 'scope' => $store ? 'store' : 'business', 'store_id' => $store?->id],
                actor: $actor,
                businessId: (int) $actor->business_id,
            );
        });
    }

    /**
     * Keep the stored secret when the submitted one is blank.
     *
     * The form cannot echo a secret back, so a submit with an untouched secret
     * field arrives empty. Treating that as "clear the key" would break every
     * live connection the first time someone edited a provider to change its
     * public key.
     *
     * @param  array<string, string>  $submitted
     * @param  array<string, mixed>|null  $stored  still encrypted
     * @param  array<int, string>  $secretKeys
     * @return array<string, string>
     */
    private function mergeStoredSecrets(array $submitted, ?array $stored, array $secretKeys): array
    {
        if ($stored === null) {
            return $submitted;
        }

        $decrypted = CredentialCipher::decrypt($stored, $secretKeys);

        foreach ($secretKeys as $key) {
            if (! isset($submitted[$key]) || $submitted[$key] === '') {
                if (isset($decrypted[$key]) && is_string($decrypted[$key])) {
                    $submitted[$key] = $decrypted[$key];
                }
            }
        }

        return $submitted;
    }

    private function methodId(string $provider): int
    {
        return (int) DB::table('payment_methods')->where('code', $provider)->value('id');
    }
}
