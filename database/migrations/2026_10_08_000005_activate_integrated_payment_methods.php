<?php

use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switches on the providers that are actually integrated.
 *
 * `paystack` was seeded `is_active = false` with the comment "Will be enabled
 * when integrated" — an accurate note at the time, and stale ever since. It was
 * harmless while the storefront filtered only on the pivot's own `is_active`,
 * but `payment_methods.is_active` is now the platform gate in
 * PaymentGatewayResolver, so leaving it false would quietly stop offering a
 * gateway that businesses have connected and that works.
 *
 * A migration rather than a seeder, deliberately: this is a one-time correction
 * to stale data, and a seeder runs on every deploy. Re-running this is a no-op,
 * and it never switches a provider *off* — that stays an admin decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        $implemented = app(PaymentGatewayManager::class)->implementedCodes();

        if ($implemented === []) {
            return;
        }

        DB::table('payment_methods')
            ->whereIn('code', $implemented)
            ->where('is_active', false)
            ->update(['is_active' => true]);
    }

    public function down(): void
    {
        // Intentionally irreversible. Which providers were switched on is not
        // recoverable — the platform never recorded it — and switching them
        // back off would break live businesses that have since connected.
    }
};
