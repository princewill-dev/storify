<?php

namespace App\Support\Payments;

use App\Services\Payments\Gateways\BitfraGateway;
use App\Services\Payments\Gateways\FlutterwaveGateway;
use App\Services\Payments\Gateways\KorapayGateway;
use App\Services\Payments\Gateways\ManualTransferGateway;
use App\Services\Payments\Gateways\MonnifyGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\Gateways\SquadGateway;
use App\Support\Plugins\PluginRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The catalogue of connectable payment providers.
 *
 * Structurally identical to {@see PluginRegistry} on
 * purpose — same shape, same `cataloguePayload()`, same registry-driven
 * validation — so the management screen drives both from data and a new
 * provider is one entry here plus one driver class, with no migration and no
 * frontend change.
 *
 * ## On the field patterns
 *
 * Unlike plugin fields, these values are never rendered into HTML — they go
 * into an `Authorization` header and a JSON column. So the pattern's job is not
 * XSS defence; it is to reject control characters and whitespace (which would
 * corrupt a header) and to catch a pasted-wrong-thing before it reaches the
 * provider. Prefixes are included where they are well known because they make
 * a good error message, but they are deliberately not exhaustive: a pattern
 * tight enough to reject a legitimate key is worse than no pattern at all, and
 * costs a business a support ticket.
 *
 * `status` marks providers whose contracts are less settled; the UI labels
 * them. `secret: true` drives both encryption at rest and the password input.
 */
final class PaymentGatewayRegistry
{
    public const STATUS_STABLE = 'stable';

    public const STATUS_BETA = 'beta';

    /**
     * Printable ASCII with no whitespace or control characters. Every
     * credential in the catalogue is matched against this as a floor.
     */
    private const SAFE = 'regex:/^[A-Za-z0-9_.\-]+$/';

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(): array
    {
        return [
            'bank_transfer' => [
                'name' => 'Bank transfer',
                'description' => 'Customers transfer to your bank account and upload proof. You confirm each payment yourself.',
                'icon' => 'fi fi-rr-bank',
                'docs_url' => '',
                'currency' => 'NGN',
                'fee_label' => 'No processing fee',
                'fee_note' => 'The money moves bank to bank, so nothing is taken per transaction.',
                'supports_refund' => false,
                'supports_partial' => true,
                'status' => self::STATUS_STABLE,
                // Credentials are the business's bank accounts, not API keys.
                'credential_source' => 'bank_accounts',
                // Nothing to visit: the customer is shown where to send money.
                'checkout_mode' => 'offline',
                'driver' => ManualTransferGateway::class,
                'fields' => [],
            ],

            'paystack' => [
                'name' => 'Paystack',
                'description' => 'Cards, bank transfer, USSD and mobile money. The gateway most Nigerian businesses already use.',
                'icon' => 'fi fi-rr-credit-card',
                'docs_url' => 'https://dashboard.paystack.com/#/settings/developers',
                'currency' => 'NGN',
                'fee_label' => '1.5% + ₦100',
                'fee_note' => 'Capped at ₦2,000. The ₦100 is waived under ₦2,500. VAT of 7.5% applies on the fee itself. International cards 3.9% + ₦100.',
                'supports_refund' => true,
                'supports_partial' => true,
                'status' => self::STATUS_STABLE,
                'credential_source' => 'keys',
                'driver' => PaystackGateway::class,
                'fields' => [
                    [
                        'key' => 'public_key',
                        'label' => 'Public key',
                        'help' => 'Settings → API Keys & Webhooks.',
                        'placeholder' => 'pk_live_…',
                        'secret' => false,
                        'rules' => ['required', 'string', 'max:255', self::SAFE, 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
                    ],
                    [
                        'key' => 'secret_key',
                        'label' => 'Secret key',
                        'help' => 'Keep this private. It can move money on your account.',
                        'placeholder' => 'sk_live_…',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE, 'regex:/^sk_(test|live)_[A-Za-z0-9]+$/'],
                    ],
                ],
            ],

            'flutterwave' => [
                'name' => 'Flutterwave',
                'description' => 'Cards, bank transfer, USSD and mobile money across Africa.',
                'icon' => 'fi fi-rr-exchange',
                'docs_url' => 'https://dashboard.flutterwave.com/dashboard/settings/apis',
                'currency' => 'NGN',
                'fee_label' => '2% local, 4.8% international',
                'fee_note' => 'Flutterwave quote this as 1.4% transaction fee plus a 0.6% platform fee. VAT of 7.5% applies on the fee. Some published comparisons still list the older 1.4%.',
                'supports_refund' => true,
                'supports_partial' => true,
                'status' => self::STATUS_STABLE,
                'credential_source' => 'keys',
                'driver' => FlutterwaveGateway::class,
                'fields' => [
                    [
                        'key' => 'public_key',
                        'label' => 'Public key',
                        'help' => 'Settings → API Keys.',
                        'placeholder' => 'FLWPUBK-…',
                        'secret' => false,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                    [
                        'key' => 'secret_key',
                        'label' => 'Secret key',
                        'help' => 'Keep this private.',
                        'placeholder' => 'FLWSECK-…',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                    [
                        'key' => 'webhook_secret',
                        'label' => 'Webhook secret hash',
                        'help' => 'The value you entered as your "secret hash" under Settings → Webhooks. Flutterwave sends it back so we can trust the request.',
                        'placeholder' => 'your-secret-hash',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                ],
            ],

            'monnify' => [
                'name' => 'Monnify',
                'description' => 'Cards, bank transfer and reserved virtual accounts, with same-day settlement.',
                'icon' => 'fi fi-rr-building',
                'docs_url' => 'https://app.monnify.com/',
                'currency' => 'NGN',
                'fee_label' => '1.5%, capped ₦2,000',
                'fee_note' => 'Applies to cards and bank transfers. International cards 3.8–4%. Rates are VAT-exclusive.',
                'supports_refund' => true,
                'supports_partial' => true,
                'status' => self::STATUS_STABLE,
                'credential_source' => 'keys',
                'driver' => MonnifyGateway::class,
                'fields' => [
                    [
                        'key' => 'api_key',
                        'label' => 'API key',
                        'help' => 'Developer → API Keys and Contract Codes.',
                        'placeholder' => 'MK_PROD_…',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                    [
                        'key' => 'secret_key',
                        'label' => 'Secret key',
                        'help' => 'Paired with the API key to obtain an access token.',
                        'placeholder' => 'your-secret-key',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                    [
                        'key' => 'contract_code',
                        'label' => 'Contract code',
                        'help' => 'Identifies which of your Monnify accounts to settle into. It must match the environment the keys belong to.',
                        'placeholder' => '1234567890',
                        'secret' => false,
                        'rules' => ['required', 'string', 'max:64', self::SAFE],
                    ],
                ],
            ],

            'squad' => [
                'name' => 'Squad',
                'description' => 'GTCO’s gateway — cards, bank transfer and USSD, with settlement to a GTBank account.',
                'icon' => 'fi fi-rr-shield-check',
                'docs_url' => 'https://dashboard.squadco.com/',
                'currency' => 'NGN',
                'fee_label' => '1.2%, capped ₦1,500',
                'fee_note' => 'Payment-link rate for local transactions. Virtual accounts 0.25%, capped ₦1,000. Rates are set by your individual agreement.',
                'supports_refund' => true,
                'supports_partial' => true,
                'status' => self::STATUS_STABLE,
                'credential_source' => 'keys',
                'driver' => SquadGateway::class,
                'fields' => [
                    [
                        'key' => 'secret_key',
                        'label' => 'Secret key',
                        'help' => 'Dashboard → API Keys. Sandbox keys begin sandbox_sk_.',
                        'placeholder' => 'sandbox_sk_…',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                ],
            ],

            'korapay' => [
                'name' => 'Korapay',
                'description' => 'Cards, bank transfer, mobile money and virtual accounts.',
                'icon' => 'fi fi-rr-wallet',
                'docs_url' => 'https://dashboard.korapay.com/settings/apis',
                'currency' => 'NGN',
                'fee_label' => '1.5%, capped ₦2,000',
                'fee_note' => 'Plus 7.5% VAT on the fee. You can choose to pass the fee to the customer at checkout.',
                'supports_refund' => true,
                'supports_partial' => true,
                'status' => self::STATUS_STABLE,
                'credential_source' => 'keys',
                'driver' => KorapayGateway::class,
                'fields' => [
                    [
                        'key' => 'public_key',
                        'label' => 'Public key',
                        'help' => 'Settings → API Keys & Webhooks.',
                        'placeholder' => 'pk_test_…',
                        'secret' => false,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                    [
                        'key' => 'secret_key',
                        'label' => 'Secret key',
                        'help' => 'Keep this private — it must never reach the browser.',
                        'placeholder' => 'sk_test_…',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                ],
            ],

            'bachs' => [
                'name' => 'Bachs',
                'description' => 'Cards, bank transfer, mobile money and stablecoin payouts across African markets.',
                'icon' => 'fi fi-rr-money',
                'docs_url' => 'https://docs.bachs.io/',
                'currency' => 'NGN',
                'fee_label' => 'Cards 2%, transfer 1.5%, crypto 1.5%',
                'fee_note' => 'Bank transfers are capped at ₦2,000. International cards 5% + 40¢.',
                'supports_refund' => true,
                'supports_partial' => true,
                'status' => self::STATUS_BETA,
                'credential_source' => 'keys',
                'fields' => [
                    [
                        'key' => 'api_key',
                        'label' => 'API key',
                        'help' => 'Dashboard → Developers. sk_sandbox_ keys test, sk_live_ keys take real money.',
                        'placeholder' => 'sk_live_…',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                    [
                        'key' => 'webhook_secret',
                        'label' => 'Webhook secret',
                        'help' => 'Set alongside the API key, so we can trust incoming webhooks.',
                        'placeholder' => 'your-webhook-secret',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                ],
            ],

            'bitfra' => [
                'name' => 'Bitfra',
                'description' => 'Non-custodial crypto payments. Funds settle straight to your own wallet.',
                'icon' => 'fi fi-rr-coins',
                'docs_url' => 'https://bitfra.net/docs',
                'currency' => 'USD',
                'fee_label' => 'No platform fee',
                'fee_note' => 'Bitfra takes no cut and never holds your funds. The customer still pays network fees, and prices are quoted in USD — so this only appears for stores charging in USD.',
                'supports_refund' => false,
                'supports_partial' => false,
                'status' => self::STATUS_STABLE,
                'credential_source' => 'keys',
                'driver' => BitfraGateway::class,
                'webhook_note' => 'Paste this into your store\'s webhook settings in Bitfra. It shows you a signing secret once, when you save the URL — copy that into the field below.',
                'fields' => [
                    [
                        'key' => 'api_key',
                        'label' => 'API key',
                        'help' => 'Developers page. Sandbox and live keys are not interchangeable.',
                        'placeholder' => 'bix-…',
                        'secret' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE, 'regex:/^bix-[a-f0-9]+-[a-f0-9]+$/i'],
                    ],
                    [
                        'key' => 'webhook_secret',
                        'label' => 'Webhook secret',
                        'help' => 'Shown once by Bitfra when you save the webhook URL above. Leave blank to connect without webhooks — payments are still confirmed by asking Bitfra directly, they just take a moment longer to land.',
                        'placeholder' => 'your-webhook-secret',
                        'secret' => true,
                        // Optional because the secret does not exist until the
                        // merchant has saved our webhook URL at Bitfra and
                        // copied back what Bitfra showed them. Requiring it up
                        // front means the form cannot be completed in the order
                        // the provider's own setup flow demands.
                        'optional' => true,
                        'rules' => ['required', 'string', 'max:255', self::SAFE],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        static $catalogue = null;

        return $catalogue ??= self::definitions();
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Field keys this provider stores encrypted.
     *
     * @return array<int, string>
     */
    public static function secretKeysFor(string $key): array
    {
        $plugin = self::get($key);

        if ($plugin === null) {
            return [];
        }

        return array_values(array_map(
            static fn (array $field): string => $field['key'],
            array_filter($plugin['fields'], static fn (array $field): bool => ($field['secret'] ?? false) === true),
        ));
    }

    /**
     * How the customer completes this provider's payment.
     *
     * `redirect` sends them to a hosted checkout; `offline` means there is
     * nothing to visit and we show them instructions instead. Exposed as data
     * so the storefront branches on this rather than on `type === 'gateway' ||
     * code.includes('paystack')`, which is what made every new gateway silently
     * route to Paystack.
     */
    public static function checkoutModeFor(string $key): string
    {
        return (string) (self::get($key)['checkout_mode'] ?? 'redirect');
    }

    /**
     * Every field the provider needs before it can take money.
     *
     * @return array<int, string>
     */
    public static function requiredKeysFor(string $key): array
    {
        $plugin = self::get($key);

        if ($plugin === null) {
            return [];
        }

        return array_values(array_map(
            static fn (array $field): string => $field['key'],
            array_filter($plugin['fields'], static fn (array $field): bool => ($field['optional'] ?? false) !== true),
        ));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rulesFor(string $key): array
    {
        $plugin = self::get($key);

        if ($plugin === null) {
            return [];
        }

        $rules = [];
        foreach ($plugin['fields'] as $field) {
            $fieldRules = $field['rules'];

            // An optional field may be left blank, but its other rules still
            // apply when something *is* entered — which is why the flag swaps
            // `required` for `nullable` rather than dropping the field's rules.
            //
            // Deliberate for anything a provider only issues once you have
            // given it a callback URL: demanding it up front makes the
            // connection impossible to complete in the order the provider
            // expects. Absent, webhooks simply never verify for that connection
            // and payments are confirmed by re-querying the provider instead.
            if (($field['optional'] ?? false) === true) {
                $fieldRules = array_values(array_filter(
                    $fieldRules,
                    static fn (mixed $rule): bool => $rule !== 'required',
                ));
                $fieldRules[] = 'nullable';
            }

            $rules[$field['key']] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messagesFor(string $key): array
    {
        $messages = [];

        foreach (self::get($key)['fields'] ?? [] as $field) {
            $messages["{$field['key']}.required"] = "{$field['label']} is required.";
            $messages["{$field['key']}.regex"] = "That does not look like a valid {$field['label']}. Copy it straight from the provider's dashboard.";
            $messages["{$field['key']}.max"] = "{$field['label']} is longer than this field allows.";
        }

        return $messages;
    }

    /**
     * The catalogue as the management screen needs it — no validation rules,
     * since the browser has no business holding them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cataloguePayload(): array
    {
        $payload = [];

        foreach (self::all() as $key => $plugin) {
            $payload[] = [
                'code' => $key,
                'name' => $plugin['name'],
                'description' => $plugin['description'],
                'icon' => $plugin['icon'],
                'docs_url' => $plugin['docs_url'],
                'currency' => $plugin['currency'],
                'fee_label' => $plugin['fee_label'],
                'fee_note' => $plugin['fee_note'],
                'supports_refund' => $plugin['supports_refund'],
                'supports_partial' => $plugin['supports_partial'],
                'status' => $plugin['status'],
                'credential_source' => $plugin['credential_source'],
                // Provider-specific wording for the webhook URL the screen
                // shows. It belongs here rather than in the SPA because it is a
                // fact about this provider's setup flow, and the screen should
                // not grow a branch per provider.
                'webhook_note' => $plugin['webhook_note'] ?? null,
                'fields' => array_map(static fn (array $field): array => [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'help' => $field['help'] ?? null,
                    'placeholder' => $field['placeholder'] ?? null,
                    'secret' => (bool) ($field['secret'] ?? false),
                    'optional' => ($field['optional'] ?? false) === true,
                ], $plugin['fields']),
            ];
        }

        return $payload;
    }

    /**
     * Validate a submitted config against the provider's field rules.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function validatedConfig(string $key, array $config): array
    {
        $validated = Validator::make($config, self::rulesFor($key), self::messagesFor($key))->validate();

        $clean = [];
        foreach (self::get($key)['fields'] as $field) {
            $value = $validated[$field['key']] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $clean[$field['key']] = (string) $value;
        }

        return $clean;
    }
}
