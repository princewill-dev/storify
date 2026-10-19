<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\PaymentMethod;
use App\Services\Payments\Connect\PlatformCommission;
use App\Services\Payments\Custody\PayoutPolicy;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-12 — platform payment methods.
 *
 * Only enabled methods are offered at storefront checkout
 * (`PaymentMethod::active()`), and only enabled gateways can be connected by
 * a business in management payment settings, so the toggle takes effect
 * immediately on both surfaces.
 *
 * There is no create/delete here on purpose: the legacy screens only listed
 * and toggled the seeded methods (paystack, bank_transfer, cash, …), and
 * adding a code that no checkout integration handles would be dead data.
 */
class PaymentMethodController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $methods = PaymentMethod::query()->orderBy('name')->get();

        return $this->ok([
            'payment_methods' => $methods->map(fn (PaymentMethod $method) => $this->payload($method))->values()->all(),
            'billing' => $this->billingPayload(),
        ]);
    }

    /**
     * The platform's own billing gateway, and what each option would do.
     *
     * This rides on the payment-methods response because this is the screen
     * that decides it: the same list of methods, the same page, the same save.
     *
     * Every option carries its own availability, not just the selected one —
     * otherwise an admin choosing between two gateways would be shown the state
     * of whichever they had already picked and would be choosing the other one
     * blind. The reason is a sentence, not a flag, because a gateway can be
     * unusable for two very different causes: no credentials on the server, or a
     * method switched off further up this same page.
     *
     * @return array<string, mixed>
     */
    private function billingPayload(): array
    {
        $gateway = app(PlatformBillingGateway::class);
        $selected = $gateway->code();
        $commission = PlatformCommission::fromSettings();
        $payoutPolicy = PayoutPolicy::current();

        return [
            'gateway' => $selected,
            'webhook_configured' => $gateway->webhookConfigured(),
            // The payout policy, beside the commission because it is the other
            // deduction on the same money path. Exposed from this finance-gated
            // read so an admin who may see the payments tab can read the figures
            // even when they cannot save settings (`PUT /admin/settings` needs
            // `admin.settings`).
            'minimum_payout_kobo' => $payoutPolicy['minimum_kobo'],
            'payout_processing_fee_kobo' => $payoutPolicy['fee_kobo'],
            // What Storify takes from a sale it processes on a business's
            // behalf. Shown here rather than on a page of its own because it is
            // the other half of the same decision: this screen already says
            // *through which gateway* the platform takes money, and this says
            // *how much of it it keeps*.
            'commission' => [
                // Percent as a percentage, not basis points: the number the
                // admin typed is the number they are shown, and the conversion
                // to basis points is an internal detail of the calculator.
                'percent' => $commission->percentBasisPoints() / 100,
                // The per-sale ceiling in kobo; zero means the percentage is
                // uncapped.
                'max_kobo' => $commission->maxKobo(),
                // Deprecated, echoed as zero for one release. The flat fee was
                // replaced by the cap, and the previous admin bundle still reads
                // this key while deciding whether the form is dirty. Reporting
                // the (inert) stored value would show a charge that no longer
                // happens.
                'fixed_kobo' => 0,
                'is_zero' => $commission->isZero(),
                // Which providers Storify can charge through on its own
                // credentials, so the screen can say what the commission applies
                // to instead of implying it applies to everything. Read from the
                // billing gateway rather than the registry because it is the
                // same declaration the charge path consults — a list built from
                // anywhere else could name a provider the platform holds no keys
                // for, and the screen would be promising a commission on a rail
                // that cannot run.
                'platform_providers' => PlatformBillingGateway::selectableCodes(),
            ],
            'options' => collect(PlatformBillingGateway::selectableCodes())
                ->map(function (string $code) use ($gateway, $selected): array {
                    $reason = $gateway->reasonFor($code);

                    return [
                        'code' => $code,
                        'name' => (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code),
                        'selected' => $code === $selected,
                        // `available` folds together "the keys are on the
                        // server" and "an admin has not switched the method
                        // off", so on its own it cannot tell an admin which of
                        // the two to go and fix. The reason sentence does.
                        'available' => $reason === null,
                        'unavailable_reason' => $reason,
                        // Readiness of the two things a delivery needs, per
                        // gateway rather than only for the selected one. A key
                        // that is present but a signing secret that is not takes
                        // a customer's money and cannot confirm it, and the
                        // failure surfaces days later as a subscription that
                        // never activated — so the gap is worth showing here.
                        'webhook_configured' => $gateway->webhookConfiguredFor($code),
                        'connect_webhook_configured' => $gateway->connectWebhookConfiguredFor($code),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    public function toggle(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $paymentMethod->update(['is_active' => ! $paymentMethod->is_active]);

        $state = $paymentMethod->is_active ? 'enabled' : 'disabled';

        Log::info('api.admin.payment_method_toggled', [
            'actor_user_id' => $request->user()?->id,
            'payment_method_id' => $paymentMethod->id,
            'code' => $paymentMethod->code,
            'is_active' => $paymentMethod->is_active,
        ]);

        return $this->ok(
            ['payment_method' => $this->payload($paymentMethod->fresh())],
            $paymentMethod->name.' '.$state.' successfully.',
        );
    }

    /**
     * The method's `config` column is deliberately not exposed: gateway
     * configs carry Paystack keys, and the legacy list rendered only
     * name/code/description/state. Business-level credentials stay on the
     * management payment-settings endpoints where the owner is scoped.
     *
     * @return array<string, mixed>
     */
    private function payload(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'name' => $method->name,
            'code' => $method->code,
            'type' => $method->type,
            'description' => $method->description,
            'is_active' => (bool) $method->is_active,
            'updated_at' => $method->updated_at?->toISOString(),
        ];
    }
}
