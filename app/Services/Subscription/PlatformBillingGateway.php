<?php

namespace App\Services\Subscription;

use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\PaymentGatewayRegistry;

/**
 * Which gateway Storify bills its own subscriptions through, and the platform's
 * credentials for it.
 *
 * Storify's own subscription charges are not a merchant's: they run on
 * credentials the platform holds, not on a connection a business made. Before
 * this existed that fact was written down as a `PaystackService` injected
 * straight into {@see SubscriptionPaymentService}, which is why the billing
 * gateway could not be anything else. Here it is one seam instead of two
 * branches: the same `PaymentGateway` contract the merchant path uses, with
 * platform credentials supplied per call.
 *
 * **Fail closed, and say why.** Every caller that finds `available()` false must
 * refuse the checkout rather than fall back to the other gateway. Charging a
 * customer through a gateway nobody chose — and that the admin may have switched
 * off — is worse than telling them to try later.
 *
 * The admin picks the gateway (`settings.billing_gateway`); the keys stay in
 * `.env`, hand-managed exactly as the platform's Paystack keys already are. The
 * admin API deliberately cannot write credentials, so this class has nothing to
 * read from a table.
 */
final class PlatformBillingGateway
{
    /** What a fresh install bills through, and what the column defaults to. */
    public const DEFAULT_CODE = 'paystack';

    /**
     * The platform's credentials, per gateway.
     *
     * `config` is where they are stored; `keys` maps a credential field name —
     * the same name a business's own connection would use, so the drivers read
     * one vocabulary — onto that config key. `required` is what the server needs
     * to *charge*: not every field a merchant would be asked for matters here.
     * Paystack, for instance, needs only the secret key to initialize and verify;
     * demanding a public key would mark a correctly configured platform
     * unconfigured.
     */
    private const CREDENTIALS = [
        'paystack' => [
            'config' => 'services.paystack',
            'keys' => [
                'secret_key' => 'secret_key',
                'public_key' => 'public_key',
            ],
            'required' => ['secret_key'],
            // Paystack signs webhooks with the secret key itself.
            'webhook' => 'secret_key',
        ],
        'bachs' => [
            'config' => 'services.bachs',
            'keys' => [
                'api_key' => 'api_key',
                'webhook_secret' => 'webhook_secret',
            ],
            'required' => ['api_key'],
            'webhook' => 'webhook_secret',
        ],
    ];

    public function __construct(
        private readonly PaymentGatewayManager $manager,
    ) {}

    /**
     * The gateway codes an admin may choose between.
     *
     * Derived from the credential map rather than from the registry: a gateway
     * the platform holds no keys for cannot be a billing gateway, however good a
     * merchant gateway it makes.
     *
     * @return array<int, string>
     */
    public static function selectableCodes(): array
    {
        return array_keys(self::CREDENTIALS);
    }

    /**
     * Read from the column on every call, deliberately un-memoised.
     *
     * The value is admin-mutable state: an admin switching the billing gateway
     * expects the next checkout — and the next webhook — to follow the switch.
     * A memo made that depend on *when the object was built*, which is harmless
     * on a classic request-per-process deployment and quietly wrong under a
     * persistent worker, where the instance outlives the request and would keep
     * answering with the gateway it saw first. One cheap query per call is a
     * small price for not having that class of bug at all.
     */
    public function code(): string
    {
        $configured = Setting::query()->value('billing_gateway');

        // A column holding something this class does not know — a gateway
        // removed from the map, or a hand-edited row — reads as the default
        // rather than as an unchargeable state. `available()` still refuses it
        // if its credentials are absent.
        return is_string($configured) && isset(self::CREDENTIALS[$configured])
            ? $configured
            : self::DEFAULT_CODE;
    }

    /** The display name, from the same catalogue the merchant screens render. */
    public function name(): string
    {
        return (string) (PaymentGatewayRegistry::get($this->code())['name'] ?? $this->code());
    }

    public function driver(): ?PaymentGateway
    {
        return $this->driverFor($this->code());
    }

    /**
     * A named gateway's driver, rather than the configured one's.
     *
     * Needed wherever the gateway is a property of the thing being acted on and
     * not of the current setting. A payment records the gateway it was charged
     * through, and asking whatever an admin has selected *since* to verify it
     * would hand a Paystack key a Bachs checkout id — a lookup that can only
     * fail, failing a payment the customer already made.
     */
    public function driverFor(string $code): ?PaymentGateway
    {
        return $this->manager->driver($code);
    }

    /**
     * The platform's credentials for the configured gateway.
     *
     * Empty rather than null when nothing is configured: every driver already
     * treats missing credentials as a refused call, and an empty set says the
     * same thing without a second code path.
     */
    public function credentials(): GatewayCredentials
    {
        return $this->credentialsFor($this->code());
    }

    public function credentialsFor(string $code): GatewayCredentials
    {
        $spec = self::CREDENTIALS[$code] ?? null;

        if ($spec === null) {
            return GatewayCredentials::empty($code);
        }

        $values = [];

        foreach ($spec['keys'] as $field => $configKey) {
            $values[$field] = config($spec['config'].'.'.$configKey);
        }

        return GatewayCredentials::make($code, $values, PaymentGatewayRegistry::secretKeysFor($code));
    }

    /**
     * Whether platform billing can run right now.
     *
     * Both halves matter and both are checked: a driver with credentials but no
     * `payment_methods` row is a gateway the admin has switched off, and the
     * switch is meant to be off on the system — this is the platform's own
     * checkout, so it is the one place the switch is easiest to forget.
     */
    public function available(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Why platform billing cannot run, or null when it can.
     *
     * A message rather than a boolean because every caller has to *say*
     * something: a customer at the plan checkout, an admin reading their own
     * screen. Returning the reason keeps the two from inventing different
     * explanations for the same state.
     */
    public function unavailableReason(): ?string
    {
        return $this->reasonFor($this->code());
    }

    /**
     * The same question asked of any gateway, so the admin's screen can report
     * what each option would do before one of them is chosen. Without this the
     * only gateway whose state could be shown is the one already selected, and
     * the admin picks the other one blind.
     */
    public function reasonFor(string $code): ?string
    {
        $problem = $this->credentialProblem($code);

        if ($problem !== null) {
            return $problem;
        }

        if (! $this->methodIsActive($code)) {
            $name = (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code);

            return "{$name} is switched off for the whole platform, so subscription payments cannot be taken through it.";
        }

        return null;
    }

    /**
     * Why this gateway's keys cannot be used, ignoring the admin's switch — or
     * null when they can.
     *
     * Split out from {@see reasonFor()} because the two halves answer different
     * questions and only one of them applies to a payment already made. An admin
     * switching a gateway off stops new checkouts; it does not un-take money the
     * provider already has, so settling an in-flight payment still has to reach
     * that provider's API. Refusing *that* would leave the customer charged and
     * unsubscribed, which is the worse of the two failures. Initialization asks
     * `available()`; verification asks this.
     */
    public function credentialProblem(string $code): ?string
    {
        $spec = self::CREDENTIALS[$code] ?? null;

        if ($spec === null) {
            return 'Storify holds no platform credentials for this gateway.';
        }

        $name = (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code);

        if ($this->manager->driver($code) === null) {
            return "{$name} has no integration in this build.";
        }

        $missing = array_values(array_filter(
            $spec['required'],
            fn (string $key): bool => ! $this->credentialsFor($code)->has($key),
        ));

        if ($missing !== []) {
            // Names the fields, never a value: this message reaches an admin's
            // browser and a log line, and one of these fields is a live key.
            return 'Its server credentials are not configured ('.implode(', ', $missing).').';
        }

        return null;
    }

    /**
     * Whether the signature this gateway's webhooks are verified with is
     * configured — separate from `available()`, because a delivery can be
     * rejected for a reason that does not stop a customer paying.
     */
    public function webhookConfigured(): bool
    {
        $spec = self::CREDENTIALS[$this->code()] ?? null;

        return $spec !== null && $this->credentials()->has($spec['webhook']);
    }

    /**
     * Whether this provider is the one the platform bills through.
     *
     * The subscription webhook endpoint accepts no other: a delivery from a
     * gateway we no longer bill through is a delivery about payments this
     * platform is not reconciling, and acting on it could activate a
     * subscription nothing was paid for.
     */
    public function handlesWebhooksFor(string $provider): bool
    {
        return $provider === $this->code() && $this->webhookConfigured();
    }

    private function methodIsActive(string $code): bool
    {
        $row = PaymentMethod::query()->where('code', $code)->first();

        // No row means no driver was ever built for it, which the registry gate
        // already handled — treat it as not offered rather than as an error.
        return $row !== null && (bool) $row->is_active;
    }
}
