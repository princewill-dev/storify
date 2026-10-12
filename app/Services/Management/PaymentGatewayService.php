<?php

namespace App\Services\Management;

use App\Models\Business;
use App\Models\Store;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Payments\Connect\ConnectResult;
use App\Services\Payments\Connect\PayoutResult;
use App\Services\Payments\Connect\PlatformPayoutService;
use App\Services\Payments\Data\ManagedAccount;
use App\Services\Payments\OnBehalf\OnBehalfProcessors;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\GatewayGrant;
use App\Support\Payments\PaymentGatewayRegistry;
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
 */
final class PaymentGatewayService
{
    public function __construct(
        // The provider-neutral seam. This service used to hold
        // `BachsConnectAccountService` and `ConnectRequirementService` directly,
        // which was the same thing while Bachs was the only provider that could
        // be provisioned for a business — and stopped being the same thing the
        // moment a second one existed. The two classes are unchanged; they are
        // simply reached through the provider's own processor now.
        private readonly OnBehalfProcessors $processors,
        private readonly PlatformPayoutService $payouts,
    ) {}

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
     * Register the bank account a business is paid into, and the connected
     * account the platform will charge through on its behalf.
     *
     * The other half of the connect flow, and reachable by every business rather
     * than only granted ones — this is the path for the business that has **no**
     * gateway of its own. It writes the same rows `connect()` does, so scope
     * precedence, the admin gate and the resolver need to know nothing new: a
     * store-scoped registration is the store's own payouts, a business-scoped one
     * is everyone's default.
     *
     * **An existing account is reused, never replaced.** A business changing its
     * bank lands on the account it already has, because the provider cannot
     * delete an account and its fee arrangement is fixed at creation — a second
     * one would be a second real account in the provider's books holding the
     * same business's identity.
     *
     * @param  array<string, mixed>  $input  validated: `store_bank_id`, or account fields plus `entity_type`
     */
    public function registerPayoutAccount(
        string $provider,
        array $input,
        User $actor,
        ?Store $store = null,
    ): ConnectResult {
        $definition = PaymentGatewayRegistry::get($provider);

        // Refused in words when the platform has no way to process for a
        // business on this provider. The message names the provider from the
        // registry, so a second managed provider needed no change here.
        $processor = $this->processors->forOrFail($provider);

        $business = Business::query()->findOrFail((int) $actor->business_id);
        $bank = $this->resolveBank($input, $actor);

        $existing = $this->existingRow($provider, $actor, $store);
        $accountId = $existing->gateway_account_id ?? null;

        $result = is_string($accountId) && $accountId !== ''
            ? $processor->registerDestination($accountId, $bank)
            : $processor->provision($business, $store, $bank, (string) $input['entity_type']);

        if (! $result->success) {
            // The row is still written when the account exists but its bank was
            // refused: without it, the next attempt would create a second
            // account rather than retrying this one's destination.
            if ($result->accountId !== null) {
                $this->persistManagedConnection($provider, $actor, $store, $result->accountId, $result->account, null, $input);
            }

            return $result;
        }

        $this->persistManagedConnection($provider, $actor, $store, (string) $result->accountId, $result->account, $result->destination, $input);

        // Everything the provider is asking for that we already hold, submitted
        // in the same breath as the registration. Doing it here rather than only
        // on the next sweep is what makes the difference between a business that
        // can take payments in a minute and one that waits an hour for a cron —
        // and the pass is idempotent, so the sweep repeating it costs a read.
        //
        // The terms acceptance is written to the row *before* this runs, because
        // this is what submits `tos_acceptance` from it.
        $requirements = $processor->submitRequirements((string) $result->accountId, $business, $store, account: $result->account);

        if ($requirements->account !== null) {
            $this->persistManagedConnection($provider, $actor, $store, (string) $result->accountId, $requirements->account, $result->destination, $input);
        }

        // The provider now pays this business itself, rather than holding the
        // balance until our sweep comes for it. Asked for at registration rather
        // than on the next sweep for the same reason the requirements pass is:
        // it is the difference between a business whose money arrives on its own
        // and one that waits for a cron that must also be alive.
        //
        // A refusal is *not* a failed registration. The account exists, it can be
        // charged, and the money is direct either way — what failed is the
        // schedule, which is a reason stated to the business rather than a reason
        // to unwind a real account at the provider. So the connection stays and
        // the message travels back on the result.
        $settlementProblem = $processor->ensureDirectSettlement(
            (string) $result->accountId,
            (bool) ($business->auto_payout ?? true),
        );

        if ($settlementProblem !== null) {
            return ConnectResult::failed($settlementProblem, [], (string) $result->accountId);
        }

        ActivityRecorder::record(
            action: 'payment_gateway.payout_account_registered',
            description: ($definition['name'] ?? $provider).' payout account registered'.($store ? " for store {$store->name}" : ''),
            old: [],
            // Never the account number: what is recorded is that a bank was
            // registered, and enough of it to recognise which one.
            new: ['account_id' => $result->accountId, 'bank' => $result->destination['bank_name'] ?? null, 'last4' => $result->destination['last4'] ?? null],
            metadata: ['provider' => $provider, 'scope' => $store ? 'store' : 'business', 'store_id' => $store?->id],
            actor: $actor,
            businessId: (int) $actor->business_id,
        );

        return $result;
    }

    /**
     * Withdraw a business's settled money to its bank, on demand.
     *
     * The admin-console counterpart to the scheduled sweep, for a business that
     * has asked or a sweep that has not run since the funds settled. Every guard
     * against paying twice or paying unsettled money lives in
     * {@see PlatformPayoutService} — this only decides *whose* account.
     */
    public function payOutNow(int $businessId, ?int $storeId = null): PayoutResult
    {
        return $this->payouts->payOut($businessId, $storeId);
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
     * The row for this provider at this scope, or a blank object when there is
     * none — so a first registration and a re-registration take the same path.
     */
    private function existingRow(string $provider, User $actor, ?Store $store): object
    {
        $row = ($store
            ? DB::table('store_payment_method')->where('store_id', $store->id)
            : DB::table('business_payment_method')->where('business_id', (int) $actor->business_id))
            ->where('payment_method_id', $this->methodId($provider))
            ->first(['id', 'gateway_account_id']);

        return $row ?? (object) ['id' => null, 'gateway_account_id' => null];
    }

    /**
     * Write the account id and its last-known state onto the connection row.
     *
     * The id goes in its own indexed column, not into the JSON: an incoming
     * webhook has to reverse-map `acct_…` back to a tenant, and a JSON
     * functional index is a fragile thing to hang that on. The state goes beside
     * it under `managed`, where it can grow without a migration.
     *
     * Merged into any config already there — a granted business may hold its own
     * encrypted keys, and this must not take them away.
     *
     * The owner's acceptance of Bachs' terms is written here too, under the same
     * `managed` block, because it is a fact about this connection rather than
     * about the request that created it: the requirement service reads it back
     * — possibly hours later, from a sweep — to submit `tos_acceptance`, and an
     * acceptance held only in the request would be an acceptance we could never
     * send. Only ever written from a truthy submission, never cleared by one.
     *
     * @param  array<string, mixed>  $input  the validated registration payload
     */
    private function persistManagedConnection(
        string $provider,
        User $actor,
        ?Store $store,
        string $accountId,
        ?ManagedAccount $account,
        ?array $destination,
        array $input = [],
    ): void {
        $attributes = $store
            ? ['store_id' => $store->id, 'payment_method_id' => $this->methodId($provider)]
            : ['business_id' => (int) $actor->business_id, 'payment_method_id' => $this->methodId($provider)];

        $model = $store ? 'store_payment_method' : 'business_payment_method';
        $existing = DB::table($model)->where($attributes)->first();
        $config = $existing ? json_decode((string) $existing->config, true) : null;
        $config = is_array($config) ? $config : [];

        $state = ($account ?? new ManagedAccount)
            ->withDestination($destination)
            ->toConfig();

        // The id is written even when the state could not be read: the account
        // exists at the provider and is the only one this business will ever
        // have, so losing the reference to it would be the worst outcome here.
        $config['managed'] = $state + $this->termsAcceptance($config, $input);

        DB::table($model)->updateOrInsert(
            $attributes,
            [
                'business_id' => (int) $actor->business_id,
                'gateway_account_id' => $accountId,
                // Switched on when the row is created, and left exactly as it
                // was when one already exists. Registering a bank says where the
                // money should go, not that a business which had deliberately
                // stopped taking payments wants to start again — and quietly
                // resuming one would take money from customers on the strength
                // of a form about bank details. The switch is `activation`.
                'is_active' => $existing?->is_active ?? true,
                'config' => json_encode($config),
                'updated_at' => now(),
                'created_at' => $existing?->created_at ?? now(),
            ],
        );
    }

    /**
     * The terms acceptance to keep on the connection.
     *
     * A registration that carried an acceptance writes a fresh one; a later
     * write that carried none — a refresh, a requirement pass — keeps whatever
     * was already recorded. The alternative, dropping it, would erase the only
     * evidence of a contractual act the first time a webhook touched the row.
     *
     * The date is stamped now rather than taken from the request, and the IP and
     * user agent are what the submission actually arrived with: an acceptance is
     * worth exactly as much as the record of who gave it, when, and from where.
     * They are stored here as we captured them and translated into the
     * provider's shapes at submission time, so that a wire format we get wrong
     * is a change in one method rather than a reason to re-ask a business for
     * something it already agreed to.
     *
     * @param  array<string, mixed>  $config  the config as stored, before this write
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function termsAcceptance(array $config, array $input): array
    {
        $keys = ['tos_accepted_at', 'tos_accepted_ip', 'tos_accepted_user_agent'];

        $existing = $config['managed'] ?? [];
        $existing = is_array($existing) ? $existing : [];

        if (! ($input['terms_accepted'] ?? false)) {
            $kept = [];

            foreach ($keys as $key) {
                if (is_string($existing[$key] ?? null)) {
                    $kept[$key] = $existing[$key];
                }
            }

            return $kept;
        }

        $ip = request()->ip();
        $agent = request()->userAgent();

        return array_filter([
            'tos_accepted_at' => now()->toIso8601String(),
            'tos_accepted_ip' => is_string($ip) && $ip !== '' ? $ip : null,
            // Truncated, not validated: it is evidence about the request that
            // carried the acceptance, and a header long enough to be a problem
            // is one we would rather keep a prefix of than refuse the
            // registration over.
            'tos_accepted_user_agent' => is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 255) : null,
        ]);
    }

    /**
     * Switch a platform-processed connection on or off without removing it.
     *
     * The answer to a question the screen could not previously answer: a
     * business taking payments through Storify had no way to stop, because
     * {@see disconnect()} refuses anything with a real account behind it and
     * `connect()` is grant-gated. Switching off is what they can do — the
     * account stays, payouts keep running, and no new charges are taken.
     *
     * Refused outright when there is no account: a row with no subaccount is a
     * keyed connection, and pausing one of those is a different feature with a
     * different meaning ("stop using my keys"), which this must not quietly
     * become. `connect()` already owns that.
     */
    public function setManagedActive(
        string $provider,
        bool $isActive,
        User $actor,
        ?Store $store = null,
    ): void {
        $definition = PaymentGatewayRegistry::get($provider);
        $model = $store ? 'store_payment_method' : 'business_payment_method';

        $query = $store
            ? DB::table($model)->where('store_id', $store->id)
            : DB::table($model)->where('business_id', (int) $actor->business_id);

        $existing = $query->where('payment_method_id', $this->methodId($provider))->first();

        if ($existing === null || ($existing->gateway_account_id ?? null) === null) {
            throw ValidationException::withMessages([
                'provider' => 'This provider is not processing payments for you, so there is nothing to switch off.',
            ]);
        }

        DB::transaction(function () use ($model, $existing, $isActive, $provider, $actor, $store, $definition): void {
            DB::table($model)->where('id', $existing->id)->update([
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);

            ActivityRecorder::record(
                action: $isActive ? 'payment_gateway.resumed' : 'payment_gateway.paused',
                description: $definition['name'].($isActive ? ' switched back on' : ' switched off').($store ? " for store {$store->name}" : ''),
                old: ['is_enabled' => (bool) $existing->is_active],
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

            // A platform-processed connection has a real account at the provider
            // behind it, which cannot be deleted and which is where this
            // business's money lives. Deleting the row would lose the only
            // reference to it and strand everything it holds, so the connection
            // is switched off instead — the account stays, payouts keep working,
            // and no new charges are taken.
            if (($existing->gateway_account_id ?? null) !== null) {
                throw ValidationException::withMessages([
                    'provider' => 'This provider is processing payments for you. Switch it off instead of removing it — your payout account has to stay connected for you to be paid.',
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
