<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Business;
use App\Services\Accounting\LedgerSetupService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

class SetupController extends ApiController
{
    use BuildsAuthResponses;
    use ResolvesManagementContext;

    public function __construct(private readonly LedgerSetupService $ledger) {}

    /**
     * Create the business for a freshly registered owner.
     *
     * Mirrors the legacy onboarding form, with two fixes it carried: the phone
     * number is actually persisted, and the business roles are guaranteed to
     * exist before they are assigned.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->business_id) {
            return $this->error('This account already has a business.', 409);
        }

        if (! $user->is_verified) {
            return $this->error('Verify your email address before setting up your business.', 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'business_location' => ['nullable', 'string', 'max:100'],
        ]);

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

        $user = $user->fresh();

        return $this->ok([
            'user' => $this->userPayload($user),
            'next' => $this->nextStep($user),
        ], 'Your business is set up. Choose a plan to get started.', 201);
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
