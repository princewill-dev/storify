<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\PaymentMethod;
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
        ]);
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
