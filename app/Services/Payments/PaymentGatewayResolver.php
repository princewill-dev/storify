<?php

namespace App\Services\Payments;

use App\Models\Store;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\ResolvedGateway;
use App\Services\Plugins\PluginResolver;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Money\ExchangeRateService;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\CredentialCipher;
use App\Support\Payments\GatewayGrant;
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
 * charged on Storify's own credentials, with the gross landing in Storify's
 * balance and the business's share credited to its wallet. A business's own keys
 * play no part, and neither do the offline rails, because a business collecting
 * cash has nothing for Storify to collect.
 *
 * That is a second resolution rule, not a special case bolted onto the first,
 * so it lives in {@see self::resolveEnforced()} and the ordinary path is left
 * untouched. It has to: a deployment that moves back to per-business keys must
 * behave exactly as it did before the platform charged anything, and a shared
 * code path with an `if` in the middle is how that stops being true by accident.
 *
 * **It is also the only path Storify charges on.** The rail that used to charge
 * on the platform's key while addressing the business's own account at the
 * provider — so the provider settled the business directly — has been deleted,
 * along with every per-business account it needed. What is left above is a
 * business charging through its own keys, and what is below is Storify
 * collecting, and there is no third arrangement.
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
     * The platform's provider switches, loaded once per request.
     *
     * Memoized because the enforced resolution asks for one of these per
     * provider and a storefront render walks every provider: without this it is
     * a fourteen-row table read fourteen times. The whole table is one query.
     *
     * @var array<string, bool>|null
     */
    private ?array $methodActive = null;

    /**
     * Whether each business Storify collects for, loaded once per request.
     *
     * @var array<int, bool>
     */
    private array $custodyByBusiness = [];

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
            $resolved = $this->resolve(
                $code,
                $business->get($code),
                $storeRows->get($code),
                $enforced,
                (int) $store->business_id,
                $currency,
            );

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

            if (! $this->isProvisioned($code, $resolved, (int) $store->business_id)) {
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
            (int) $store->business_id,
            $this->currencyFor($store),
        );

        return $this->enforcedUnavailableReason($enforced, $resolved, $enforced, $this->currencyFor($store));
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
            $businessId,
            // Asked of the store when one was named and of the business otherwise,
            // because this is reached by the till and by the invoice path as well as
            // by a screen, and a caller that forgets to say which currency it is
            // asking about must not be the reason a sale is collected that cannot be
            // credited. Resolved here rather than taken as an argument for exactly
            // that reason: a parameter with a safe-looking default is a parameter
            // somebody eventually leaves out.
            $this->currencyForScope($businessId, $storeId),
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
        // loop, and each is a query.
        //
        // The own-keys question is asked of {@see GatewayGrant} rather than read
        // off `businesses.can_connect_own_gateway` here, because that column is
        // only one of the two ways a business reaches this ability — the top
        // plan grants it as well — and a screen reading the column alone would
        // deny the entitlement to the businesses that paid for it. It is the
        // same method `PaymentGatewayService::connect()` refuses through, so the
        // screen cannot be told a regime the server would not honour, and the
        // enforced-processor case comes back false for the same reason it is
        // refused on write.
        //
        // `auto_payout` rides along here because it is read for the same reason
        // and at the same cost: the screen promises the owner their money is
        // withdrawn automatically, and an admin can switch that off, so the
        // promise has to come from the column the send actually reads —
        // {@see PayoutSweepService::candidates()}. A missing row — no business —
        // resolves to on, which is the column's own default.
        $businessRow = DB::table('businesses')
            ->where('id', $businessId)
            ->first(['business_code', 'auto_payout']);

        $businessCode = $businessRow?->business_code;
        $enforced = SystemProcessor::stored();
        $canConnectOwn = GatewayGrant::mayConnectOwn($businessId);
        $autoPayout = (bool) ($businessRow?->auto_payout ?? true);

        $states = [];

        foreach (PaymentGatewayRegistry::keys() as $code) {
            $resolved = $this->resolve($code, $business->get($code), $storeRows->get($code), $enforced, $businessId, $currency ?? $this->businessCurrency($businessId));
            $required = PaymentGatewayRegistry::requiredKeysFor($code);

            $method = DB::table('payment_methods')->where('code', $code)->first();

            $states[] = [
                'code' => $code,
                // The name travels with the state, not only with the catalogue.
                //
                // A screen can hold a provider's state without holding its
                // catalogue entry — the business's own screen drops every
                // definition it may not connect, while deliberately keeping the
                // state of anything already connected, so a downgraded business
                // can still see and remove what it has. Without the name here
                // that row has nothing to call itself but its code.
                'name' => PaymentGatewayRegistry::get($code)['name'] ?? $code,
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
                // Whether the platform can take a charge through this provider
                // at all — the fact the screen needs before offering it as one
                // Storify could process. Asked of the platform's credentials,
                // which is what the answer turns on; see
                // {@see PlatformBillingGateway::canChargeThrough()}.
                'is_managed_supported' => $this->platform->canChargeThrough($code),
                // Whether the platform is processing every business's payments
                // itself, and through what. Sent per provider for the same
                // reason the grant above is: the screen renders one card per
                // provider and must not have to make a second request to learn
                // the regime it is looking at.
                'platform_enforced' => $enforced !== null,
                'platform_enforced_code' => $enforced,
                // "Complete" means different things per regime: keys for an
                // own-key connection, and for one the platform processes nothing
                // at all — the charge is Storify's own and carries nothing of the
                // business's. The same answer {@see self::platformIsProvisioned()}
                // gives for the platform's half, so a screen that said "ready"
                // while checkout offered nothing would not be possible.
                'has_credentials' => $resolved->isManaged()
                    || $resolved->credentials->isComplete($required),
                // Masked values only — the screen shows which key is stored,
                // never the key. Secrets never leave the server in a response.
                'masked' => $resolved->credentials->masked(),
                // Whether this business's money is collected and paid out by
                // Storify. Sent with every provider because it is a
                // business-wide fact the screen states while looking at a
                // provider card — and because several of the fields below read
                // differently under it.
                'custody' => $resolved->custody,
                // The URL this business pastes into the provider's dashboard.
                //
                // Present whether or not the provider is connected, because the
                // order matters: some providers only issue the webhook secret
                // *after* you have saved a URL with them, so the merchant has to
                // be able to read this before they can fill in the form.
                //
                // Null for a connection the platform processes: the charges are
                // Storify's, so the deliveries go to the platform's endpoint.
                // There is nothing for this business to paste anywhere, and
                // showing them a URL that would never receive anything would be
                // worse than showing none.
                'webhook_url' => $resolved->isManaged() ? null : $this->webhookUrl($code, $method, $store, $businessCode),
                'unavailable_reason' => $this->unavailableReason($code, $resolved, $currency, $businessId, $enforced),
            ];
        }

        return $states;
    }

    /**
     * @param  string|null  $enforced  the platform's system processor, or null
     *                                 when the platform is not enforcing one
     * @param  int|null  $businessId  whose rows these are. Only the enforced
     *                                path needs it, and it needs it *because* a
     *                                business it collects for may have no row
     *                                here at all — see
     *                                {@see self::resolveEnforced()}.
     */
    private function resolve(
        string $code,
        ?object $businessRow,
        ?object $storeRow,
        ?string $enforced = null,
        ?int $businessId = null,
        ?string $currency = null,
    ): ResolvedGateway {
        if ($enforced !== null) {
            return $this->resolveEnforced($code, $businessRow, $storeRow, $enforced, $businessId, $currency);
        }

        $secrets = PaymentGatewayRegistry::secretKeysFor($code);
        $effective = $storeRow ?? $businessRow;

        // Enablement and the connection's details resolve independently: a store
        // row decides *whether*, and supplies its own details only when it has
        // any. A store row with no config therefore inherits the business's —
        // which is what makes every existing plain-assignment row keep working.
        $detail = ($storeRow !== null && $this->carriesConnection($storeRow)) ? $storeRow : $businessRow;

        $config = $this->decrypt($detail?->config, $secrets);
        $credentials = GatewayCredentials::make($code, $config, $secrets);

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
            paused: false,
            // Deliberately false, and this is the important half of the change.
            // A business under custody is charged through the *platform's*
            // credentials, and the only path that supplies them is
            // {@see self::resolveEnforced()} — reached when an admin has selected
            // a system processor. Nothing enforced means nothing of Storify's is
            // charging here, so this row's own keys are the whole story and there
            // is nothing collected to hold. Saying `true` here would have the
            // webhook processor credit a wallet for money sitting in the
            // business's own provider account: paid twice, once by the provider
            // and once by our sweep, for one sale.
            custody: false,
        );
    }

    /**
     * The platform-enforced resolution: one provider, Storify's credentials, and
     * nothing else.
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
     * 3. **The charge is Storify's own.** The credentials are the platform's,
     *    with no per-business account attached to them, because there is no
     *    longer any such thing. See the note on `$custody` below.
     * 4. **A destination is never required to charge.** Now that the money lands
     *    in Storify's balance there is nothing to strand: a business with no bank
     *    on file can sell all day, and is paid as soon as it names one — see
     *    {@see self::isProvisioned()}.
     *
     * ## A per-business row is not required either
     *
     * The fourth difference from the ordinary path is the one an operator will
     * notice, and it is a consequence of the other three rather than a rule of
     * its own. There is nothing per a business to *hold* any more: the charge is
     * Storify's, the money lands in Storify's balance, and the business's own
     * rows — credentials, an account at the provider, a configuration — carry
     * none of it. So a business that has never touched this screen at all is
     * still chargeable the moment it is flipped, and the row here is read for
     * exactly one thing: whether the owner has switched Storify payments **off**
     * for this store. No row means nobody said off, which is the same answer a
     * row that says on gives.
     *
     * Custody and the platform's own provider switch are therefore read from
     * where they actually live — `businesses.custody_enabled` and
     * `payment_methods.is_active` — rather than off a row that may not exist.
     * Both are memoized, so the fourteen provider lookups a storefront render
     * does are two queries.
     *
     * ## Custody is required, and that is not a restriction but a fact
     *
     * The platform can only charge on a business's behalf *while holding the
     * money for it*, because the gross arrives in Storify's balance either way
     * and there is no second rail left to settle a business directly. A business
     * that is not under custody therefore cannot be charged through the platform
     * at all: doing so would bank its customers' money in Storify's balance with
     * nothing recording that the business is owed it, which is precisely the
     * outcome the whole change exists to prevent.
     *
     * So the two predicates are one, and this method refuses rather than
     * degrading. An admin who has selected a processor while a business is not
     * yet flipped sees that business named by the enforcement pre-flight rather
     * than a storefront that quietly took an unpayable payment.
     *
     * ## And the money has to be money the wallet can hold
     *
     * The third condition on `$custody`, and the same kind of fact as the other
     * two. The wallet, the platform's custody accounts and the payout rail are
     * denominated in one currency, so a sale collected in another is a sale whose
     * figures cannot be written down anywhere — the business's share is the
     * charge less our commission (and, where the business bears it, the
     * provider's fee), and on a converted charge the amount is in the store's
     * currency while the deductions are the wallet's. The
     * customer would pay, the money would land in Storify's balance, and the
     * business's credit would be the store's number read as naira — short by the
     * rate, or enormous, depending on which side of the naira the currency sits.
     *
     * So it is refused here instead, at the only moment where refusing costs
     * nothing: a store priced in a currency this platform cannot hold simply has
     * no way to be charged through Storify, and
     * {@see self::enforcedUnavailableReason()} says so in the owner's own terms
     * rather than leaving an empty checkout unexplained. The two money paths
     * refuse the same thing a second time — see
     * {@see Transaction::isInCustodyCurrency()} — because this one decides on
     * today's store currency and the charge is settled up to two days later.
     */
    private function resolveEnforced(
        string $code,
        ?object $businessRow,
        ?object $storeRow,
        string $enforced,
        ?int $businessId,
        ?string $currency = null,
    ): ResolvedGateway {
        $secrets = PaymentGatewayRegistry::secretKeysFor($code);
        $effective = $storeRow ?? $businessRow;

        $isSelected = $code === $enforced && $this->platform->canChargeThrough($code);
        $custody = $businessId !== null
            && $this->businessIsUnderCustody($businessId)
            && $this->custodyCanHold($code, $currency);
        $chargeable = $isSelected && $custody;

        // The owner's own switch, and the only thing the rows are read for. An
        // absent row is not a "no": the default is on, because a business that
        // has never opened this screen has not asked us to stop.
        //
        // The effective row is the one asked, because that is where the owner's
        // switch is written, and a store that merely inherits is switched off by
        // *that* row's switch rather than by one of its own.
        $switchedOff = $effective !== null && ! (bool) $effective->is_active;

        // Empty unless the platform is the one charging. A provider that is
        // selected but whose business is not collected for is one nothing can be
        // charged through, and empty credentials say that in the same vocabulary
        // every other unchargeable connection already uses.
        $credentials = $chargeable
            ? $this->platform->credentialsFor($code)
            : GatewayCredentials::make($code, [], $secrets);

        return new ResolvedGateway(
            code: $code,
            isEnabled: $chargeable
                && $this->platformMethodActive($code)
                && ! $switchedOff,
            credentials: $credentials,
            // A row exists or it does not; `none` is what a business with
            // nothing on file resolves to, and the screen needs that as much
            // under enforcement as it does without it.
            source: $storeRow ? 'store' : ($businessRow ? 'business' : 'none'),
            connectionId: $effective?->id !== null ? (int) $effective->id : null,
            businessConnected: $businessRow !== null && (bool) $businessRow->is_active,
            storeOverrides: $storeRow !== null,
            hasActiveConnection: $effective !== null && (bool) $effective->is_active,
            paused: $chargeable && $switchedOff,
            custody: $chargeable && $custody,
        );
    }

    /**
     * Whether this business collects through Storify rather than charging
     * through keys of its own.
     *
     * Read from the business rather than off a payment-method row, and that is
     * the change rather than a detail: a row is no longer required for a
     * collected business to trade, so reading custody off one would have made
     * the business with *nothing* connected the one business that could not
     * sell. Memoized per business because one render asks about the same
     * business for every provider.
     */
    private function businessIsUnderCustody(int $businessId): bool
    {
        return $this->custodyByBusiness[$businessId] ??= (bool) DB::table('businesses')
            ->where('id', $businessId)
            ->value('custody_enabled');
    }

    /**
     * Whether the platform offers this provider at all.
     *
     * The admin console's switch, and the one gate that still applies when no
     * per-business row exists to carry a copy of it. Read from
     * `payment_methods` directly — not through a joined row — for that reason.
     */
    private function platformMethodActive(string $code): bool
    {
        $this->methodActive ??= DB::table('payment_methods')
            ->pluck('is_active', 'code')
            ->map(fn ($active): bool => (bool) $active)
            ->all();

        return $this->methodActive[$code] ?? false;
    }

    /**
     * Whether this row states a connection of its own, rather than being a
     * bare "this store may use it" assignment.
     *
     * The `config` half is the pre-existing test. The account half used to sit
     * beside it, so that a store could register its own subaccount without also
     * carrying a config — but a connection is now only ever credentials, and a
     * row with none is an assignment.
     */
    private function carriesConnection(object $row): bool
    {
        return ! empty($row->config);
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
        if ($resolved->isManaged()) {
            return $this->platformIsProvisioned($code);
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
     * One thing, and it is the whole of it: the platform holds a key for this
     * provider. There used to be a second — the business's account at the
     * provider had to be open and cleared for a collection capability — and its
     * removal is the point of the change rather than a relaxation of it. The
     * charge names nothing of the business's any more, so the provider's view of
     * any account cannot gate it.
     *
     * **Where the money goes is still not one of these**, and never was: there is
     * nowhere for a charge to be *lost*. Collected money is held for the business
     * and paid out once there is somewhere to pay it, and a business with no bank
     * on file can trade all day in the meantime. Requiring a destination to
     * *sell* was the gate this whole change exists to open — it stopped a
     * business taking a customer's money over paperwork that could arrive
     * tomorrow, and the failure it produced, a shop that cannot ring up a sale,
     * is worse than the one it prevented. Whether a business can be *paid* is a
     * separate question, asked by the payout sweep and reported on the
     * dashboard, and the one it answers is not this one.
     */
    private function platformIsProvisioned(string $code): bool
    {
        return $this->platform->credentialProblem($code) === null;
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
            return $this->enforcedUnavailableReason($code, $resolved, $enforced, $currency);
        }

        // No row at all is "nothing connected", and there is nothing to explain
        // — including under the enforced path above, which returns first. A row
        // holding a switch and nothing else is the same answer: it is the
        // business's own off-switch from a spell under enforcement, not a
        // connection, and "some credentials are missing" would send the owner
        // looking for keys they are not meant to have.
        if ($resolved->source === 'none' || $resolved->credentials->isEmpty()) {
            return null;
        }

        // A connection the platform processes. Each of these is a different
        // thing for the owner to do — or wait for — so they are not collapsed
        // into one "payout account not ready".
        if ($resolved->isManaged()) {
            return $this->platformUnavailableReason($resolved);
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
     * this provider is even the enforced one, then the two facts about *this*
     * store that no connection state could fix — its currency and whether the
     * business is one Storify collects for — and only then the connection. Each
     * earlier answer makes the later questions meaningless.
     *
     * Five sentences, where there were four branches and two of them were about
     * accounts. The account ones are gone with the accounts. In their place is
     * the one state the deleted rail leaves behind: a business an admin has not
     * yet flipped to custody, which cannot be charged through the platform
     * because the platform would be banking its customers' money with nothing
     * recording that the business is owed it. That is an admin's step, not the
     * business's, and the sentence says so rather than sending the owner hunting
     * for a form that does not exist. The fifth is the currency, which is the
     * one of the five an owner can act on themselves.
     */
    private function enforcedUnavailableReason(string $code, ResolvedGateway $resolved, string $enforced, ?string $currency = null): ?string
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

        // Priced in a currency the wallet cannot hold. Placed *before* the custody
        // sentence below, because a business that is collected for is exactly the
        // one this applies to, and "not collecting payments for this business yet"
        // would send its owner to wait for an admin step that would change nothing.
        // The actionable half is the store's own setting, so that is what the
        // sentence names — and it is deliberately not "connect your own keys",
        // which enforcement forbids.
        if ($currency !== null && ! $this->custodyCanHold($code, $currency)) {
            $provider = $this->providerCurrency($code);

            return sprintf(
                'Storify collects payments in %s, and this store is priced in %s. A store priced in anything but %s cannot take payments through Storify.',
                $provider,
                $currency,
                $provider,
            );
        }

        // The enforced provider, and this business is not one Storify collects
        // for. Named before anything about the connection, which is not what is
        // holding it up — no connection state could make this chargeable.
        if (! $resolved->custody) {
            return 'Storify is not collecting payments for this business yet, so it cannot take payments here.';
        }

        $reason = $this->platformUnavailableReason($resolved);

        if ($reason !== null) {
            return $reason;
        }

        // Everything about the connection is fine and it is still off, which
        // means the switch that turned it off was the business's own — at the
        // store, or on the row the connection came from. Dormant rather than
        // broken, and reversible by its owner.
        if (! $resolved->isEnabled) {
            return 'Payments are switched off for this store.';
        }

        return null;
    }

    /**
     * Why a platform-processed connection cannot take money, or null when it can.
     *
     * Two answers, where there used to be five. The three that went were all
     * about the business's *account* at the provider — under review, unchecked,
     * outstanding requirements — and there is no such account any more. They were
     * also, by the end, the statements this change existed to stop making: each
     * one reported a business as unable to sell over the state of a thing its
     * payment never named.
     *
     * The order of what is left is still load-bearing: the owner's own switch
     * comes first, because it explains the other away. Telling someone Storify
     * cannot process payments when they switched it off last week would send them
     * to support for no reason.
     *
     * Every one of these is about **taking** money. A missing payout destination
     * used to be on the list and is not any more: it stopped a business selling
     * over a bank account it had not named yet, which is the failure this design
     * was changed to remove. Being unable to be paid is a real state, and it is
     * reported where it belongs — on the dashboard, and by the payout sweep — not
     * by hiding the checkout from a customer who is trying to give the business
     * money.
     */
    private function platformUnavailableReason(ResolvedGateway $resolved): ?string
    {
        if ($resolved->paused) {
            return 'You have switched Storify payments off. Switch it back on to take payments again.';
        }

        if ($this->platform->credentialProblem($resolved->code) !== null) {
            return 'Storify cannot process payments at the moment.';
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
     * Whether a sale on a store priced in `$currency` is one Storify can collect
     * and hold for the business.
     *
     * Two conditions, and the second is the one that would silently rot if a
     * provider in another currency were ever put behind the platform's keys:
     *
     * 1. **The provider has to charge in the store's own currency.** Otherwise the
     *    charge is converted, and `transactions.amount` — the figure the wallet
     *    credits from — is in a different currency from the provider fee and the
     *    commission beside it.
     * 2. **And that currency has to be the one the wallet holds.** The wallet's
     *    columns are kobo of one currency, the platform's custody accounts hold one
     *    balance, and the payout rail sends one currency; a sale collected in
     *    anything else has nowhere to be recorded even when the sum is arithmetically
     *    sound.
     *
     * `$currency` is null where the caller has no store to ask — a screen looking at
     * a whole business rather than one of its stores, or a provider resolved by id
     * alone. Null is answered `false`, which is the safe direction: it refuses to
     * collect on a store whose currency nobody has established, and the charge falls
     * back to the business's own keys exactly as it does for a currency that is
     * known and wrong.
     */
    private function custodyCanHold(string $code, ?string $currency): bool
    {
        if ($currency === null) {
            return false;
        }

        $provider = $this->providerCurrency($code);

        return $provider === $currency
            && $provider === strtoupper((string) config('custody.currency', 'NGN'));
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
     * The currency of whichever of the two the caller named, in the same order
     * {@see StoreCurrency} uses.
     *
     * The store wins when there is one, because that is the order everywhere else:
     * a store may price in its own currency while its business prices in another.
     */
    private function currencyForScope(int $businessId, ?int $storeId): string
    {
        if ($storeId !== null) {
            $store = Store::query()->find($storeId);

            if ($store !== null) {
                return $this->currencyFor($store);
            }
        }

        return $this->businessCurrency($businessId);
    }

    /**
     * The business's own currency, for a scope that has no store to ask.
     *
     * The middle term of `store → business → platform default`, read straight from
     * the column rather than through the model, beside the other two reads in this
     * class that go to `businesses` directly.
     */
    private function businessCurrency(int $businessId): string
    {
        $currency = DB::table('businesses')->where('id', $businessId)->value('currency');

        return is_string($currency) && $currency !== ''
            ? strtoupper($currency)
            : self::DEFAULT_CURRENCY;
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
    /**
     * The business's own rows, keyed by provider code.
     *
     * Custody used to ride along on every one of these, because the business
     * was guaranteed to have a row and one fact could then be read at no cost.
     * It is not guaranteed to have one any more — and a business with no row is
     * exactly the case this has to get right — so custody is read from the
     * business itself. See {@see self::businessIsUnderCustody()}.
     *
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
