<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\SetupBusinessRequest;
use App\Services\Management\BusinessSetupService;
use Illuminate\Http\JsonResponse;

class SetupController extends ApiController
{
    use BuildsAuthResponses;
    use ResolvesManagementContext;

    public function __construct(private readonly BusinessSetupService $setup) {}

    /**
     * Create the business for a freshly registered owner.
     *
     * Mirrors the legacy onboarding form, with two fixes it carried: the phone
     * number is actually persisted, and the business roles are guaranteed to
     * exist before they are assigned. Both live in BusinessSetupService now;
     * this endpoint keeps the refusal codes (409 already-linked, 403
     * unverified) and the 201 envelope.
     */
    public function store(SetupBusinessRequest $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->business_id) {
            return $this->error('This account already has a business.', 409);
        }

        if (! $user->is_verified) {
            return $this->error('Verify your email address before setting up your business.', 403);
        }

        $this->setup->create($user, $request->validated());

        $user = $user->fresh();

        return $this->ok([
            'user' => $this->userPayload($user),
            'next' => $this->nextStep($user),
        ], 'Your business is set up. Choose a plan to get started.', 201);
    }
}
