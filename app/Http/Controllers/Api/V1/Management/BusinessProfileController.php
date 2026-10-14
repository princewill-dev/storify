<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\UpdateBusinessProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The business's own name, and its owner's.
 *
 * Both reached the database without anybody choosing them: registration was
 * not asked for a name, so one was derived from the email address, and the
 * business inherited a second placeholder of the same kind. This is where they
 * are corrected: the "Setup payment" card on the welcome page leads to the
 * screen that mounts this form.
 *
 * ## Scoped to the caller, and never by request
 *
 * The business is `$user->business`. There is no id in the payload and no
 * lookup by one, so one business editing another's row is not a mistake this
 * controller can make — the same rule the rest of the management API follows.
 */
class BusinessProfileController extends ApiController
{
    use BuildsAuthResponses;
    use ResolvesManagementContext;

    /**
     * Write the three fields, all of them tenant-scoped.
     *
     * One transaction, because two of them are the same fact about the same
     * account seen from two sides: a receipt that carries the new business name
     * beside the old owner name is not a state worth being able to reach, even
     * briefly.
     */
    public function update(UpdateBusinessProfileRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $business = $user->business;

        if (! $business) {
            return $this->error('Set up your business before updating its details.', 422);
        }

        $data = $request->validated();

        DB::transaction(function () use ($user, $business, $data) {
            $business->update(['name' => $data['name']]);

            $user->forceFill([
                'name' => $data['owner_name'],
                'phone' => $data['phone'] ?? null,
            ])->save();
        });

        $user = $user->fresh();

        return $this->ok([
            'user' => $this->userPayload($user),
        ], 'Your business details are saved.');
    }
}
