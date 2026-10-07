<?php

namespace App\Services\Management;

use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\LedgerSetupService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * The onboarding "create my business" workflow.
 *
 * The controller keeps the HTTP shape (the 409/403 guards and the 201
 * envelope); this service owns the multi-table write: the business row, the
 * owner link on `users`, per-business role provisioning, and the accounting
 * bootstrap.
 *
 * The transaction deliberately wraps only the two row writes. Role
 * provisioning and the ledger bootstrap run after the commit, in that order,
 * as they did before this extraction — they are idempotent, touch other
 * tables, and a failure there must not roll the business back.
 */
final class BusinessSetupService
{
    public function __construct(private readonly LedgerSetupService $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Business
    {
        $business = DB::transaction(function () use ($user, $data) {
            $business = Business::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'business_location' => $data['business_location'] ?? null,
                'status' => 'active',
            ]);

            // The legacy form validated a phone number and then dropped it.
            $user->forceFill([
                'business_id' => $business->id,
                'phone' => $user->phone ?: ($data['phone'] ?? null),
            ])->save();

            return $business;
        });

        $this->provisionRoles($business);
        $this->ledger->ensureForBusiness($business->id);

        Log::info('api.management.business_setup', [
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        return $business;
    }

    /**
     * Roles are created per business. On a database where the permission
     * catalogue was never seeded the owner would silently end up with no
     * permissions at all, so seed it first when the table is empty.
     */
    private function provisionRoles(Business $business): void
    {
        $seeder = new SpatiePermissionSeeder;

        if (Permission::query()->doesntExist()) {
            $seeder->run();
        }

        $seeder->createRolesForBusiness($business);
    }
}
