<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Database\Seeder;

/**
 * Keeps the `payment_methods` table in step with the code catalogue.
 *
 * The registry is the source of truth — this table exists so other tables can
 * hold a foreign key to a provider, not to be edited by hand. Seeding from it
 * means the two cannot drift: adding a provider to the registry and its driver
 * is all that is needed for it to appear.
 *
 * Three deliberate behaviours:
 *
 * 1. **`is_active` follows the driver.** A provider only becomes available once
 *    something can actually take money with it. Without this, an early stage
 *    would offer a gateway on the storefront that fails at checkout.
 * 2. **Existing rows keep their `is_active`.** That column is also an admin
 *    switch; re-seeding must not silently undo a decision made in the console.
 *    It is set on creation and on promotion (when a driver arrives), never
 *    downgraded.
 * 3. **No secrets are written here.** The previous version copied
 *    `PAYSTACK_SECRET_KEY` from the environment into this table's `config`
 *    column, which made the platform's own key a silent fallback for any
 *    business that had connected nothing. Credentials belong to a business, in
 *    an encrypted column, and nowhere else.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(PaymentGatewayManager $gateways): void
    {
        foreach (PaymentGatewayRegistry::all() as $code => $definition) {
            // No driver, no row. Seeding a provider that cannot take money yet
            // would create a row this seeder then has to guess whether to
            // activate later — and it cannot tell a fresh row from one an admin
            // deliberately switched off. Absent is unambiguous, and the
            // management screen already explains it as "not available yet".
            if (! $gateways->isImplemented($code)) {
                continue;
            }

            $method = PaymentMethod::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'type' => $this->typeFor($definition),
                    'description' => $definition['description'],
                    'is_active' => true,
                ],
            );

            // Descriptive fields only. `is_active` is also an admin switch, so
            // re-seeding must never silently undo a decision made in the console.
            $method->update([
                'name' => $definition['name'],
                'type' => $this->typeFor($definition),
                'description' => $definition['description'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function typeFor(array $definition): string
    {
        return ($definition['credential_source'] ?? 'keys') === 'bank_accounts'
            ? 'traditional'
            : 'gateway';
    }
}
