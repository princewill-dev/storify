<?php

namespace App\Services\Management;

use App\Models\Business;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\GatewayGrant;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 *
 * ## It no longer creates anything at a provider
 *
 * This service used to provision — a connected account at Bachs, a subaccount at
 * Paystack, a payout destination, a terms acceptance, a settlement schedule. All
 * of that is gone with the rail that needed it: under collect-and-pay-out there
 * is nothing per business at the provider to create, because the charge is
 * Storify's own and the money is paid out from the wallet to a bank account
 * named here. What is left is what the words always said: this service writes
 * the business's own rows, and one column on the business saying where its money
 * goes.
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

        // Refused here rather than only in the request, because the screen is not
        // the only caller: this is the same check the legacy WS-11 surface runs,
        // and a rule that lives in one controller is a rule the next caller
        // never hears about.
        GatewayGrant::assertMayConnectOwnKeys((int) $actor->business_id, $provider);

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
     * Name the bank account this business's sales are paid into.
     *
     * Which is now the whole of what "registering a payout account" means. There
     * is no account to open at a provider and no destination to submit: the
     * money is collected into Storify's balance and the sweep pays it out from
     * the business's wallet to whichever of the business's own bank accounts is
     * named here — `PayoutSweepService` reads `businesses.payout_bank_id` and
     * nothing else.
     *
     * Reachable by every business rather than only granted ones, and that is the
     * point of the change rather than a detail of it: a business with no gateway
     * of its own names a bank and starts selling. It is also **not** a
     * pre-condition of selling — a business that has not named one can trade all
     * day, and the money waits in its wallet — so this failing is never the
     * reason a customer cannot pay.
     *
     * The account is read from `store_banks` through the actor's business scope
     * rather than from the request, so the number that reaches a payout is the
     * stored one.
     *
     * @param  array<string, mixed>  $input  validated: `store_bank_id`, or the account fields
     */
    public function setPayoutBank(
        string $provider,
        array $input,
        User $actor,
        ?Store $store = null,
    ): void {
        $definition = PaymentGatewayRegistry::get($provider);

        // A store scope used to be refused here, and the refusal was right for
        // its time: the destination was a single column on the business and a
        // payout was one transfer from one balance, so a store-scoped write would
        // have quietly redirected every other store's money.
        //
        // Both halves of that are no longer true. A store has its own wallet —
        // `custody.per_store_wallets` — and the sweep now pays each destination
        // its own transfer, so a store naming its own account names money that
        // *is* separated from every other store's. The store write is allowed
        // now, and gated on the same flag so a store cannot name a bank before
        // the run would honour it.
        if ($store !== null) {
            if (! config('custody.per_store_wallets', false)) {
                throw ValidationException::withMessages([
                    'store_id' => 'Per-store payout accounts are not enabled yet.',
                ]);
            }

            $this->assertStoreIsOurs($store, $actor);
        }

        $business = Business::query()->findOrFail((int) $actor->business_id);
        $bank = $this->resolveBank($input, $actor);

        // Only a stored row can be pointed at. The typed-detail path is kept
        // working — a client that has just added an account may submit its
        // details rather than coming back for the id — by matching it back to the
        // row holding them, so a pointer exists afterwards either way.
        $bankId = $this->bankIdFor($bank, (int) $actor->business_id, $input);

        // Refused rather than written as null. Writing null would clear a
        // destination the business already had, on a submission that named one,
        // and answer `200` — so a business with a working bank would silently
        // stop being paid, and the reply would say it had just told us where to
        // send the money. The sweep reads this column and nothing else, so a
        // silent null here is a business that never gets paid again and has no
        // way to find out why.
        if ($bankId === null) {
            throw ValidationException::withMessages([
                'account_number' => 'We could not match those details to an account on your business. Add the bank account first, then choose it from your list.',
            ]);
        }

        // The store's own column when a store was named, the business's
        // otherwise. Two columns rather than one, because they mean different
        // things: the business's is the destination for everything a store does
        // not claim, and a store's is an override for that store alone.
        if ($store !== null) {
            $store->forceFill(['payout_bank_id' => $bankId])->save();
        } else {
            $business->forceFill(['payout_bank_id' => $bankId])->save();
        }

        ActivityRecorder::record(
            action: 'payment_gateway.payout_account_registered',
            description: ($definition['name'] ?? $provider).' payout bank named',
            old: [],
            // Never the account number: what is recorded is that a bank was
            // named, and enough of it to recognise which one.
            new: ['bank' => $bank['bank_name'] ?? null, 'last4' => substr($bank['account_number'], -4)],
            metadata: [
                'provider' => $provider,
                'scope' => $store !== null ? 'store' : 'business',
                'store_id' => $store?->id,
            ],
            actor: $actor,
            businessId: (int) $actor->business_id,
        );
    }

    /**
     * A store the caller may name a payout account for.
     *
     * The business check is the tenant one; the store check is the same rule
     * `accessibleStores()` applies everywhere else, so a restricted staff member
     * cannot name a store they cannot see.
     */
    private function assertStoreIsOurs(Store $store, User $actor): void
    {
        if ((int) $store->business_id !== (int) $actor->business_id) {
            throw ValidationException::withMessages([
                'store_id' => 'That store is not one of this business\'s stores.',
            ]);
        }
    }

    /**
     * Switch Storify's collection for this business on or off without losing the
     * state.
     *
     * The answer to a question the screen could not previously answer: a business
     * being collected for had no way to stop, because `connect()` is grant-gated
     * and there are no keys of its own to switch off. Switching off stops new
     * charges and nothing else — money already collected keeps being paid out,
     * because it is the business's and holding it hostage over a decision about
     * new sales would be the wrong trade.
     *
     * ## Why this is written to a row that carries nothing else
     *
     * Under collect-and-pay-out a business needs no row at all to be charged: the
     * credentials are Storify's and the settlement is the wallet's. The row here
     * exists for this switch and only for this switch, which is why one is
     * created when there is none and only when switching *off* — the default is
     * on, so "on" needs no row to say so. The resolver reads it in exactly that
     * spirit: no row means nobody objected.
     *
     * **Refused unless Storify is actually collecting for this business.** The
     * switch means "stop charging on my behalf", so there has to be something to
     * stop; and a stray row saying off would otherwise lie in wait for the day an
     * admin flips the business, blocking its sales over a decision taken about a
     * rail it was not on. A keyed connection is paused through `connect()`
     * instead, which is a different statement ("stop using my keys") and owns
     * that meaning.
     */
    public function setManagedActive(
        string $provider,
        bool $isActive,
        User $actor,
        ?Store $store = null,
    ): void {
        $definition = PaymentGatewayRegistry::get($provider);

        $business = Business::query()->findOrFail((int) $actor->business_id);

        if (! $business->isUnderCustody()
            || SystemProcessor::stored() !== $provider
            || ! PlatformBillingGateway::canChargeThrough($provider)) {
            throw ValidationException::withMessages([
                'provider' => 'Storify is not collecting payments for you on this provider, so there is nothing to switch off.',
            ]);
        }

        $model = $store ? 'store_payment_method' : 'business_payment_method';

        $query = $store
            ? DB::table($model)->where('store_id', $store->id)
            : DB::table($model)->where('business_id', (int) $actor->business_id);

        $existing = $query->where('payment_method_id', $this->methodId($provider))->first();

        // Switched back on with nothing recorded: the default is already what
        // was asked for, so there is no row to write and nothing to log. Doing
        // nothing quietly is right here — the screen reports the state it reads
        // back, and a trail entry for a no-op would be a lie about what changed.
        if ($existing === null && $isActive) {
            return;
        }

        DB::transaction(function () use ($model, $existing, $isActive, $provider, $actor, $store, $definition): void {
            $attributes = $store
                ? ['store_id' => $store->id, 'payment_method_id' => $this->methodId($provider)]
                : ['business_id' => (int) $actor->business_id, 'payment_method_id' => $this->methodId($provider)];

            DB::table($model)->updateOrInsert($attributes, [
                // Null, not an empty JSON object: this row is a switch, and a
                // `config` that looked like a connection would be read as one —
                // by `carriesConnection()`, which decides whether a store row
                // overrides its business's details.
                'business_id' => (int) $actor->business_id,
                'is_active' => $isActive,
                'config' => $existing?->config ?? null,
                'updated_at' => now(),
                'created_at' => $existing?->created_at ?? now(),
            ]);

            ActivityRecorder::record(
                action: $isActive ? 'payment_gateway.resumed' : 'payment_gateway.paused',
                description: $definition['name'].($isActive ? ' switched back on' : ' switched off').($store ? " for store {$store->name}" : ''),
                old: ['is_enabled' => (bool) ($existing->is_active ?? true)],
                new: ['is_enabled' => $isActive],
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

            // The provider Storify is collecting through cannot be removed, and
            // the reason is now about the *record* rather than about an account
            // holding money: removing the row would delete the only place the
            // business's switch-off is written, silently resuming its sales.
            // Switching it off is the way to stop, and it is reversible.
            if (SystemProcessor::stored() === $provider) {
                throw ValidationException::withMessages([
                    'provider' => 'Storify is processing payments through this provider. Switch it off instead of removing it.',
                ]);
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
     * The bank to register, from whichever of the two ways it was named.
     *
     * A stored account wins when one was chosen, and it is read through the
     * business scope rather than trusted from the request — the request has
     * already checked the id belongs to this business, and reading it here means
     * the account number that reaches the provider is the stored one rather than
     * a value posted alongside it.
     *
     * @param  array<string, mixed>  $input
     * @return array{account_number: string, account_name: string, bank_code: string, bank_name: string|null}
     */
    private function resolveBank(array $input, User $actor): array
    {
        $bankId = $input['store_bank_id'] ?? null;

        if ($bankId !== null && $bankId !== '') {
            $stored = DB::table('store_banks')
                ->where('id', (int) $bankId)
                ->where('business_id', (int) $actor->business_id)
                ->first(['account_number', 'account_name', 'bank_code', 'bank_name']);

            if ($stored === null) {
                throw ValidationException::withMessages([
                    'store_bank_id' => 'That bank account is not one of this business\'s accounts.',
                ]);
            }

            return [
                'account_number' => (string) $stored->account_number,
                'account_name' => (string) $stored->account_name,
                'bank_code' => (string) $stored->bank_code,
                'bank_name' => $stored->bank_name === null ? null : (string) $stored->bank_name,
            ];
        }

        $number = (string) ($input['account_number'] ?? '');

        if ($number === '') {
            throw ValidationException::withMessages([
                'account_number' => 'Enter the account number, or choose a bank account already on file.',
            ]);
        }

        return [
            'account_number' => $number,
            'account_name' => strtoupper(trim((string) $input['account_name'])),
            'bank_code' => (string) $input['bank_code'],
            'bank_name' => isset($input['bank_name']) ? (string) $input['bank_name'] : null,
        ];
    }

    /**
     * The `store_banks` row the pointer should name.
     *
     * A chosen account names itself. Typed details are matched back to the row
     * holding them, scoped to the business, because the pointer is a foreign key
     * and there has to be a row for it to point at — and because "I typed my
     * account number in" and "I picked it from my list" are the same act from the
     * owner's side.
     *
     * When nothing matches, this returns `null` and no row is written — see
     * `ws40payoutbankTest`, which pins that. A bank the platform is going to send
     * money to has to be one the business already holds; inventing a row from
     * posted arguments would mean writing down an account number nobody verified
     * and then trusting it with somebody's money.
     *
     * Returning `null` is not the same as answering `200`, and the caller is
     * where that is settled: `setPayoutBank()` refuses the submission outright
     * rather than writing a null pointer over a destination the business already
     * had. The two halves are the same rule seen from either end — nothing is
     * invented, and nothing is silently taken away.
     *
     * @param  array{account_number: string, bank_code: string}  $bank
     * @param  array<string, mixed>  $input
     */
    private function bankIdFor(array $bank, int $businessId, array $input): ?int
    {
        $bankId = $input['store_bank_id'] ?? null;

        if ($bankId !== null && $bankId !== '') {
            return (int) $bankId;
        }

        $id = DB::table('store_banks')
            ->where('business_id', $businessId)
            ->where('account_number', $bank['account_number'])
            ->where('bank_code', $bank['bank_code'])
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
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
