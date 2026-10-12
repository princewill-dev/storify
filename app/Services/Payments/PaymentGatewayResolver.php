<?php

namespace App\Services\Payments;

use App\Models\Store;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\ManagedAccount;
use App\Services\Payments\Data\ResolvedGateway;
use App\Services\Plugins\PluginResolver;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\PaymentWebhookUrl;
use App\Support\Payments\SystemProcessor;
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
 *        AND the provider's currency can be priced for this store
 *        AND the credentials are complete
 *
 * ## When the platform enforces one processor
 *
 * An admin can select a single system payment processor
 * ({@see SystemProcessor}). From then on the precedence above is not consulted
 * for *new* charges: one provider is offered — the selected one — and it is
 * charged through the business's registered settlement account with the split
 * made at the provider. A business's own keys play no part, and neither do the
 * offline rails, because a business collecting cash has nothing for us to
 * split.
 *
 * That is a second resolution rule, not a special case bolted onto the first,
 * so it lives in {@see self::resolveEnforced()} and the ordinary path is left
 * untouched. It has to: every deployment that has not turned the switch on must
 * behave exactly as it did before this existed, and a shared code path with an
 * `if` in the middle is how that stops being true by accident.
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

    public function __construct(
        private readonly ExchangeRateService $rates,
        // A managed connection charges with the platform's key, so the resolver
        // needs to know whether the platform holds one. Read through the same
        // seam the platform's own billing uses, rather than reaching into config
        // here: two readers of one secret are two chances to disagree.
        private readonly PlatformBillingGateway $platform,
    ) {}

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
        // Read once for the whole loop rather than once per provider: it is one
        // platform-wide value, and asking it fourteen times per storefront render
        // would be fourteen identical queries.
        $enforced = SystemProcessor::stored();

        $usable = [];

        foreach (PaymentGatewayRegistry::keys() as $code) {
            $resolved = $this->resolve($code, $business->get($code), $storeRows->get($code), $enforced);

            if (! $resolved->isEnabled) {
                continue;
            }

            // A provider in another currency is offered as long as we can price
            // it — the store amount is converted into the provider's currency
            // at the call site. This is still why nothing anywhere needs
            // `if ($code === 'bitfra')`; it is now also why a missing rate hides
            // a gateway rather than mispricing it.
            //
            // Offline rails are the exception and keep the exact match. There is
            // no provider to hand a converted figure to: `bank_transfer` settles
            // by a human reading a slip whose amount the payer typed, and its
            // storefront path hard-codes naira. Conversion only means anything
            // where something else charges the money.
            if (! $this->canPrice($code, $currency)) {
                continue;
            }

            if (! $this->isProvisioned($code, $resolved, (int) $store->business_id, $enforced !== null)) {
                continue;
            }

            $usable[$code] = $resolved;
        }

        return $usable;
    }

    /**
     * Why this store has no payment method to offer, or null when it has one.
     *
     * Exists because an empty checkout with no explanation is the worst version
     * of this state, and the switch creates it deliberately for a business that
     * has not registered a settlement account. The customer is owed the sentence;
     * the business's own screen is owed the same one rather than a different one
     * invented at the call site.
     *
     * Null when the platform is not enforcing anything: with the switch off, an
     * empty list means the ordinary "this store has not connected anything",
     * which the storefront has always rendered as simply no options, and
     * inventing a message for it now would be a change to the off deployment this
     * whole design is built to leave alone.
     *
     * Under enforcement the answer is the selected processor's own reason, from
     * the same ordering {@see self::states()} reports — so what the customer
     * reads and what the owner reads cannot disagree.
     */
    public function storeUnavailableReason(Store $store): ?string
    {
        $enforced = SystemProcessor::stored();

        if ($enforced === null) {
            return null;
        }

        $resolved = $this->resolve(
            $enforced,
            $this->businessRows((int) $store->business_id)->get($enforced),
            $this->storeRows((int) $store->id)->get($enforced),
            $enforced,
        );

        return $this->enforcedUnavailableReason($enforced, $resolved, $enforced, (int) $store->business_id);
    }

    /**
     * Whether the platform can charge on this business's behalf right now.
     *
     * The same test {@see self::states()} applies, asked at business scope: an
     * account registered at the enforced processor that can be charged *and* can
     * be paid out to. It is deliberately not a reason string — the callers that
     * need one get it from `states()`, and the caller that needs a yes or no is
     * the sign-in gate deciding whether a business still has to be asked where
     * its money goes.
     *
     * Only the business's own row is read. A store's separate settlement account
     * is that store's, and does not answer the question this asks: whether the
     * business has named a destination at all.
     *
     * False when the stored processor is one this build cannot run, because
     * `SystemProcessor::code()` is null — a state in which nobody can be charged,
     * and which is an admin's to fix rather than a business's.
     */
    public function enforcedProcessorReady(int $businessId): bool
    {
        $code = SystemProcessor::code();

        if ($code === null) {
            return false;
        }

        $resolved = $this->resolve(
            $code,
            $this->businessRows($businessId)->get($code),
            null,
            SystemProcessor::stored(),
        );

        return $this->managedIsProvisioned($code, $resolved, true);
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
            SystemProcessor::stored(),
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
        // Resolved once rather than per provider — neither can change inside the
        // loop, and each is a query. The grant is read here rather than passed
        // in because the screen must never be told a regime the server would not
        // honour: this is the same column `PaymentGatewayService::connect()`
        // refuses on.
        //
        // `auto_payout` rides along here because it is read for the same reason
        // and at the same cost: the screen promises the owner their money is
        // withdrawn automatically, and an admin can switch that off, so the
        // promise has to come from the column the sweep actually reads. A
        // missing row — no business — resolves to on, matching
        // `PlatformPayoutService::autoPayoutEnabled()`.
        $businessRow = DB::table('businesses')
            ->where('id', $businessId)
            ->first(['business_code', 'can_connect_own_gateway', 'auto_payout']);

        $businessCode = $businessRow?->business_code;
        $enforced = SystemProcessor::stored();
        // The grant is reported as *usable* only when the platform is not
        // enforcing a processor. An admin holding a grant while the switch is on
        // is not a state the screen should render a key form for: the connect
        // would be refused (`GatewayGrant`), so showing it would be a form whose
        // only outcome is an error.
        $canConnectOwn = (bool) ($businessRow?->can_connect_own_gateway ?? false) && $enforced === null;
        $autoPayout = (bool) ($businessRow?->auto_payout ?? true);

        $states = [];

        foreach (PaymentGatewayRegistry::keys() as $code) {
            $resolved = $this->resolve($code, $business->get($code), $storeRows->get($code), $enforced);
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
                // Which of the two ways this provider is provisioned, or `none`.
                // The screen renders a payout-account form rather than a
                // credentials form off this, so it must reflect what the
                // resolver *did*, not what the provider supports.
                'payment_mode' => $resolved->paymentMode(),
                // Switched off by the business itself, as opposed to never
                // connected or switched off by an admin. The screen offers the
                // switch back on this flag and on nothing else.
                'is_paused' => $resolved->paused,
                // Whether the business may ever connect its own keys. Sent with
                // every provider rather than once, because the screen asks the
                // question per card and a second request would be a second
                // source of truth.
                'can_connect_own_gateway' => $canConnectOwn,
                // Whether settled money is withdrawn to the business's bank on
                // its own, or held in its provider account until an admin pays
                // it out. Sent with every provider for the same reason as the
                // grant above: it is a business-wide fact the screen states
                // while looking at a provider card, and a second request for it
                // would be a second source of truth. It is not per-provider
                // state, and nothing on this screen can change it.
                'auto_payout' => $autoPayout,
                'is_managed_supported' => PaymentGatewayRegistry::supportsManaged($code),
                // Whether the platform is processing every business's payments
                // itself, and through what. Sent per provider for the same
                // reason the grant above is: the screen renders one card per
                // provider and must not have to make a second request to learn
                // the regime it is looking at.
                'platform_enforced' => $enforced !== null,
                'platform_enforced_code' => $enforced,
                // "Complete" means different things per regime: keys for an
                // own-key connection, a cleared account for a managed one.
                'has_credentials' => $resolved->isManaged()
                    ? ($resolved->managed?->canCharge($code) ?? false)
                    : $resolved->credentials->isComplete($required),
                // Masked values only — the screen shows which key is stored,
                // never the key. Secrets never leave the server in a response.
                'masked' => $resolved->credentials->masked(),
                // The connected account, when there is one. Null for own-key
                // connections, so the screen can tell "no payout account yet"
                // from "payout account not ready".
                'managed' => $resolved->managed === null ? null : [
                    'account_id' => $resolved->credentials->accountId(),
                    'status' => $resolved->managed->status,
                    'is_active' => $resolved->managed->isActive,
                    'can_charge' => $resolved->managed->canCharge($code),
                    'can_be_paid_out' => $resolved->managed->canBePaidOut(),
                    'granted_capabilities' => $resolved->managed->grantedCapabilities($code),
                    'payout_destination' => $resolved->managed->maskedDestination(),
                    'requirements' => $resolved->managed->requirementPayload(),
                ],
                // The URL this business pastes into the provider's dashboard.
                //
                // Present whether or not the provider is connected, because the
                // order matters: some providers only issue the webhook secret
                // *after* you have saved a URL with them, so the merchant has to
                // be able to read this before they can fill in the form.
                //
                // Null for a managed connection: the charges are the platform's,
                // so the deliveries go to the platform's endpoint. There is
                // nothing for this business to paste anywhere, and showing them a
                // URL that would never receive anything would be worse than
                // showing none.
                'webhook_url' => $resolved->isManaged() ? null : $this->webhookUrl($code, $method, $store, $businessCode),
                'unavailable_reason' => $this->unavailableReason($code, $resolved, $currency, $businessId, $enforced),
            ];
        }

        return $states;
    }

    /**
     * @param  string|null  $enforced  the platform's system processor, or null
     *                                 when the platform is not enforcing one
     */
    private function resolve(string $code, ?object $businessRow, ?object $storeRow, ?string $enforced = null): ResolvedGateway
    {
        if ($enforced !== null) {
            return $this->resolveEnforced($code, $businessRow, $storeRow, $enforced);
        }

        $secrets = PaymentGatewayRegistry::secretKeysFor($code);
        $effective = $storeRow ?? $businessRow;

        // Enablement and the connection's details resolve independently: a store
        // row decides *whether*, and supplies its own details only when it has
        // any. A store row with neither config nor an account therefore inherits
        // the business's — which is what makes every existing plain-assignment
        // row keep working.
        //
        // "Details" now means either of two things: the business's own keys, or
        // a connected account the platform charges through on their behalf.
        $detail = ($storeRow !== null && $this->carriesConnection($storeRow)) ? $storeRow : $businessRow;

        $config = $this->decrypt($detail?->config, $secrets);
        $credentials = GatewayCredentials::make($code, $config, $secrets);
        $managed = null;

        // A connected account is used only when this row holds no usable keys of
        // its own. Keys win, deliberately: a business that has connected its own
        // gateway means it, and only a business an admin has granted that
        // ability can be in this position at all. The account is kept either
        // way — it lives in its own column, not in the `config` a keyed connect
        // rewrites — so revoking the grant puts the business back on it.
        if ($credentials->isEmpty() && ($accountId = $this->accountIdOf($detail)) !== null) {
            $managed = ManagedAccount::fromConfig(is_array($config['managed'] ?? null) ? $config['managed'] : null);

            // Built from the platform's credentials for this provider, with the
            // account attached. `withAccountId` is what makes the driver send
            // `X-Account-Id`; without it the charge would silently be the
            // platform's own.
            $credentials = $this->platform->credentialsFor($code)->withAccountId($accountId);
        }

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
            managed: $managed,
            // Off, and off on purpose. Read from `$detail` — the row the account
            // came from — because that is the row `setManagedActive()` writes,
            // and a store that merely inherits a paused business connection is
            // paused by *that* row's switch rather than by one of its own.
            paused: $managed !== null && ! (bool) ($detail?->is_active ?? false),
        );
    }

    /**
     * The platform-enforced resolution: one provider, the business's settlement
     * account, and nothing else.
     *
     * Four differences from {@see self::resolve()}, and each is the point of the
     * switch rather than a detail of it:
     *
     * 1. **Only the selected processor is offered.** Every other code — the ones
     *    a business connected, the ones an admin left switched on, the offline
     *    rails — resolves to not-enabled. That is the discount the switch buys:
     *    under enforcement there is exactly one way money may arrive.
     * 2. **The business's own keys are ignored.** `config` is never read for
     *    credentials. This is a live fix as much as a new rule: the ordinary path
     *    lets stored keys keep charging after an admin has taken the grant away,
     *    so the console's promise that they "stop being used immediately" was
     *    not true of the code.
     * 3. **A store row cannot mask the business's account.** The ordinary path
     *    prefers the store's row when it carries a connection, which for a store
     *    holding its own keys would hide the account the money has to reach. Here
     *    the account is sought on the store's row and then the business's, and
     *    keys are not a connection at all — hence the deliberate absence of
     *    {@see self::carriesConnection()} from this method.
     * 4. **An account that cannot be paid out is not usable.** Charging money we
     *    cannot hand to the business is the failure this change exists to remove,
     *    so it is refused before a customer is offered the method — enforced in
     *    {@see self::isProvisioned()}.
     */
    private function resolveEnforced(string $code, ?object $businessRow, ?object $storeRow, string $enforced): ResolvedGateway
    {
        $secrets = PaymentGatewayRegistry::secretKeysFor($code);
        $effective = $storeRow ?? $businessRow;

        // The nearest row that holds an account. A store may register its own
        // settlement account; failing that the business's is used.
        $accountRow = $this->accountIdOf($storeRow) !== null
            ? $storeRow
            : ($this->accountIdOf($businessRow) !== null ? $businessRow : null);
        $accountId = $this->accountIdOf($accountRow);

        $isSelected = $code === $enforced && PaymentGatewayRegistry::supportsManaged($code);

        $managed = null;
        // Empty until an account is found: under enforcement there is no other
        // way to hold the credentials to charge with, so a provider without a
        // usable account is one nothing can be charged through.
        $credentials = GatewayCredentials::make($code, [], $secrets);

        if ($isSelected && $accountId !== null) {
            $config = $this->decrypt($accountRow?->config, $secrets);
            $managed = ManagedAccount::fromConfig(is_array($config['managed'] ?? null) ? $config['managed'] : null);

            // The platform's credentials with the business's account attached —
            // the same construction the ordinary managed path uses, so the
            // driver sees one shape whichever switch is on.
            $credentials = $this->platform->credentialsFor($code)->withAccountId($accountId);
        }

        return new ResolvedGateway(
            code: $code,
            isEnabled: $isSelected
                && $this->platformOffers($businessRow, $storeRow)
                && (bool) ($effective?->is_active ?? false)
                // A connection switched off at its own row is off, exactly as it
                // is when the platform is not enforcing anything: the owner's
                // switch still means what it says.
                && ($accountRow === null || (bool) $accountRow->is_active),
            credentials: $credentials,
            source: $storeRow ? 'store' : ($businessRow ? 'business' : 'none'),
            connectionId: $effective?->id !== null ? (int) $effective->id : null,
            businessConnected: $businessRow !== null && (bool) $businessRow->is_active,
            storeOverrides: $storeRow !== null,
            // The row the account came from, not `$effective`: with enforcement
            // on, what the screen calls "connected" is the row carrying the
            // account, and a store's own key row is not one.
            hasActiveConnection: $accountRow !== null && (bool) $accountRow->is_active,
            managed: $managed,
            paused: $managed !== null && ! (bool) ($accountRow?->is_active ?? false),
        );
    }

    /**
     * Whether this row states a connection of its own, rather than being a
     * bare "this store may use it" assignment.
     *
     * The `config` half is the pre-existing test and is unchanged. The account
     * half is what lets a store register its own subaccount without also having
     * to carry a config — which it has no reason to, since a managed connection
     * holds no credentials at all.
     */
    private function carriesConnection(object $row): bool
    {
        return ! empty($row->config) || $this->accountIdOf($row) !== null;
    }

    private function accountIdOf(?object $row): ?string
    {
        $id = $row->gateway_account_id ?? null;

        return is_string($id) && $id !== '' ? $id : null;
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
    private function isProvisioned(string $code, ResolvedGateway $resolved, ?int $businessId, bool $enforced = false): bool
    {
        if ($resolved->isManaged()) {
            return $this->managedIsProvisioned($code, $resolved, $enforced);
        }

        if (! $this->usesCredentials($code)) {
            return $businessId === null || $this->businessHasBankAccount($businessId);
        }

        return ! $resolved->credentials->isEmpty()
            && $resolved->credentials->isComplete(PaymentGatewayRegistry::requiredKeysFor($code));
    }

    /**
     * Whether the platform can charge on this business's behalf here.
     *
     * Three things, and all three are needed before a customer is offered the
     * method: the platform holds a key to charge with, the account is active,
     * and it has been cleared for at least one collection capability. This is
     * the first of the two guards against the header going missing — a store
     * with no usable subaccount is never offered the gateway at all, so there is
     * no path on which a charge could fall through to the platform's own
     * account. The driver carries the second guard, for callers that skip this.
     *
     * **`canBePaidOut()` is required only when the platform is enforcing a
     * processor**, and the asymmetry is the point. A managed connection that
     * cannot be paid out still *works*: the money sits in the connected account
     * and the console's sweep pays it out once a destination is registered, so
     * refusing to charge would stop a business trading over paperwork that can
     * arrive tomorrow. Under enforcement there is no sweep to wait for — the
     * provider pays the business directly, out of the same settlement — so an
     * account with nowhere to pay out to is a charge that strands money, and it
     * has to be refused *before* the customer is offered the method.
     */
    private function managedIsProvisioned(string $code, ResolvedGateway $resolved, bool $enforced = false): bool
    {
        $account = $resolved->managed;

        if ($this->platform->credentialProblem($code) !== null || ! ($account?->canCharge($code) ?? false)) {
            return false;
        }

        return ! $enforced || $account->canBePaidOut();
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

    /**
     * Whether the business has named which of its accounts its sales are paid
     * into — which is a different question from {@see self::businessHasBankAccount()}.
     *
     * That one asks whether an account exists to receive a manual transfer, and a
     * business may hold several for its own bookkeeping. This one asks whether the
     * owner has answered the question only they can answer, and it is the answer
     * the provider's subaccount is built from.
     *
     * Null is a "no": the callers that pass one either have no business in hand or
     * are describing a business that cannot be resolved, and both are states in
     * which nothing has been named.
     */
    private function hasPayoutBank(?int $businessId): bool
    {
        if ($businessId === null) {
            return false;
        }

        return DB::table('businesses')
            ->where('id', $businessId)
            ->whereNotNull('payout_bank_id')
            ->exists();
    }

    private function unavailableReason(string $code, ResolvedGateway $resolved, ?string $currency, int $businessId, ?string $enforced = null): ?string
    {
        $method = DB::table('payment_methods')->where('code', $code)->first();

        if ($method === null) {
            return 'Not available on this platform yet.';
        }

        if (! $method->is_active) {
            return 'Not enabled on this platform.';
        }

        // Placed before the `source === 'none'` early return below, which reads
        // "nothing is connected, so there is nothing to explain". Under
        // enforcement that is exactly the state that *most* needs explaining: a
        // business with nothing registered is a business that cannot sell.
        if ($enforced !== null) {
            return $this->enforcedUnavailableReason($code, $resolved, $enforced, $businessId);
        }

        if ($resolved->source === 'none') {
            return null;
        }

        // A connection the platform processes. Each of these is a different
        // thing for the owner to do — or wait for — so they are not collapsed
        // into one "payout account not ready".
        if ($resolved->isManaged()) {
            return $this->managedUnavailableReason($code, $resolved);
        }

        if (! $this->usesCredentials($code) && ! $this->businessHasBankAccount($businessId)) {
            return 'Add a verified bank account before enabling bank transfers.';
        }

        if (! $resolved->credentials->isComplete(PaymentGatewayRegistry::requiredKeysFor($code))) {
            return 'Connected, but some credentials are missing.';
        }

        if ($currency !== null && ! $this->canPrice($code, $currency)) {
            $provider = $this->providerCurrency($code);

            // Offline rails genuinely cannot serve another currency; a redirect
            // provider only cannot because no rate has been fetched. Saying
            // which is which is the difference between "fix your settings" and
            // "wait for the job", and this screen previously said neither.
            if (PaymentGatewayRegistry::checkoutModeFor($code) === 'offline') {
                return "Collects in {$provider}, so it cannot be used by a store priced in {$currency}.";
            }

            return "Charges in {$provider}. No {$provider} exchange rate has been fetched yet — the daily rate job has not run.";
        }

        return null;
    }

    /**
     * Why a provider cannot take money while the platform enforces one
     * processor, or null when it can.
     *
     * Ordered from the outside in: the platform's own state first, then whether
     * this provider is even the enforced one, and only then the connection. Each
     * earlier answer makes the later questions meaningless — a business with no
     * settlement account does not need to be told its account is under review.
     *
     * The two answers for "no account at the enforced processor" are told apart
     * by the business's own bank account rather than by the provider's view of
     * it, because they are different situations with different owners: a business
     * that has never named an account has something to do, and one that has named
     * it but holds no account at *this* processor is waiting on a setup step —
     * which happens when an admin changes the processor under a business that had
     * already registered. Saying "you have not registered a settlement account"
     * to the second is what the admin read as the platform blaming the wrong
     * party for its own plumbing.
     */
    private function enforcedUnavailableReason(string $code, ResolvedGateway $resolved, string $enforced, ?int $businessId = null): ?string
    {
        // The admin selected a processor this build cannot run. Nothing can be
        // charged through *anything*, and saying so once is better than
        // fourteen provider-shaped variations of the same fact.
        if (($problem = SystemProcessor::unavailableReason()) !== null) {
            return $problem;
        }

        // A provider that is not the enforced one. Not an error on the
        // business's part, so the message is a statement of the rule rather than
        // something for them to fix — the one thing they could do (connect it)
        // is precisely what enforcement forbids.
        if (! SystemProcessor::is($code)) {
            return 'Storify processes all payments through '.SystemProcessor::name().'.';
        }

        // The enforced provider, and this business has no account on it. Named
        // before the account checks below, which would otherwise all describe an
        // account that does not exist.
        if ($resolved->managed === null) {
            return $this->hasPayoutBank($businessId)
                ? 'Your bank account is registered, but your payment account is not set up yet.'
                : 'Set the bank account your sales are paid into to take payments.';
        }

        // Past this point it is the account's own state, and it reads the same
        // under enforcement as without it.
        $reason = $this->managedUnavailableReason($code, $resolved);

        if ($reason !== null) {
            return $reason;
        }

        // The one genuine difference from the managed path: an account with
        // nowhere to pay out to is refused *here*, because a direct settlement
        // has no later step in which to fix it — see
        // {@see self::managedIsProvisioned()}.
        if (! $resolved->managed->canBePaidOut()) {
            return 'Add the bank account your sales are paid into.';
        }

        // Everything about the connection is fine and it is still off, which
        // means the switch that turned it off was the business's own — at the
        // store, or on the row the account came from. Dormant rather than
        // broken, and reversible by its owner.
        if (! $resolved->isEnabled) {
            return 'Payments are switched off for this store.';
        }

        return null;
    }

    /**
     * Why a platform-processed connection cannot take money, or null when it can.
     *
     * Ordered by which party has to act, and the order is load-bearing: the
     * owner's own switch comes first, because it explains the others away — a
     * paused account that is also under review is paused, and the review is not
     * news. After that: an unconfigured platform is ours to fix, an account
     * still under review is the provider's, and a missing destination is the
     * owner's again.
     */
    private function managedUnavailableReason(string $code, ResolvedGateway $resolved): ?string
    {
        // First, because it is the only one of these the owner did themselves,
        // and telling someone their account is under review when they switched
        // it off last week would send them to support for no reason.
        if ($resolved->paused) {
            return 'You have switched Storify payments off. Switch it back on to take payments again — your payout account stays connected either way.';
        }

        if ($this->platform->credentialProblem($code) !== null) {
            return 'Storify cannot process payments at the moment.';
        }

        $account = $resolved->managed;

        if ($account === null || ! $account->isActive) {
            return 'Your payout account is still being reviewed. You will be able to take payments once it is approved.';
        }

        if ($account->grantedCapabilities($code) === []) {
            return 'Your payout account cannot take payments yet — the provider is still checking it.';
        }

        if ($account->hasOutstandingRequirements()) {
            return 'Your payout account still needs some information before it can take payments.';
        }

        if (! $account->canBePaidOut()) {
            return 'Add the bank account your sales are paid into.';
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

    /**
     * Whether a store priced in `$currency` can be charged through `$code`.
     *
     * An offline rail must collect in the store's own currency — nothing
     * converts for it. Everything else needs only a rate, which is the one
     * thing that can be missing, and the reason this replaced a bare equality
     * check.
     */
    private function canPrice(string $code, string $currency): bool
    {
        $provider = $this->providerCurrency($code);

        if (PaymentGatewayRegistry::checkoutModeFor($code) === 'offline') {
            return $provider === $currency;
        }

        return $provider === $currency
            || $this->rates->convert(100, $currency, $provider) !== null;
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
        return StoreCurrency::for($store);
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
                // Read as a column so a managed connection is decided from one
                // scalar rather than by decoding JSON on every resolve.
                'bpm.gateway_account_id',
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
                'spm.gateway_account_id',
            ])
            ->keyBy('code');
    }
}
