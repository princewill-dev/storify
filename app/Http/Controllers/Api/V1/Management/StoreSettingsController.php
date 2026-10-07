<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StoreSettings\AssignStoreStaffRequest;
use App\Http\Requests\Management\StoreSettings\ServiceChargeRequest;
use App\Http\Requests\Management\StoreSettings\UpdateStoreSettingsRequest;
use App\Http\Resources\Management\StoreSettings\ServiceChargeResource;
use App\Http\Resources\Management\StoreSettings\StaffResource;
use App\Http\Resources\Management\StoreSettings\StoreResource;
use App\Http\Resources\Management\StoreSettings\StoreSettingsResource;
use App\Models\Store;
use App\Models\User;
use App\Repositories\Management\StoreSettingsRepository;
use App\Rules\ReservedStoreSlug;
use App\Services\Management\StoreSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * WS-04 — the store settings workspace.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope and
 * the refusal order) and the audit log lines stay here. The payload rules live
 * in App\Http\Requests\Management\StoreSettings, the settings reads (the eager
 * load the workspace renders, the available-staff query and the slug
 * de-duplication) in App\Repositories\Management\StoreSettingsRepository, the
 * details/logo write workflow — transaction boundary, orphan cleanup and
 * old-logo deletion — in App\Services\Management\StoreSettingsService, and the
 * workspace payload in App\Http\Resources\Management\StoreSettings.
 *
 * Three things deliberately stay in this body, so their place in the refusal
 * order is unchanged:
 *  - the store guard is ResolvesManagementContext::authorizeStore() called
 *    first, not middleware and not FormRequest::authorize();
 *  - the live-storefront slug refusal (422, keyed on `slug`) runs before the
 *    service, and the `ReservedStoreSlug` check still runs only while the
 *    storefront is offline — a store-independent FormRequest rule set cannot
 *    express that order without changing which refusal a live storefront
 *    sees;
 *  - `{charge}` rows are resolved through the store relation, so an id from
 *    another store is a 404 rather than an edit target (the legacy overloaded
 *    PUT did no such check).
 *
 * Known consequence of the FormRequest extraction: a request that is both
 * unauthorised and malformed is now a 422 rather than a 403, codebase-wide.
 * A valid payload from an unauthorised caller still gets 403.
 */
class StoreSettingsController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StoreSettingsRepository $repository,
        private readonly StoreSettingsService $service,
    ) {}

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $store = $this->repository->settingsLoad($store);

        $available = $this->repository->availableStaff(
            $store->business_id,
            $store->assignedStaff->pluck('id'),
        );

        return $this->ok((new StoreSettingsResource($store, $available))->resolve($request));
    }

    /**
     * Store details, branding and socials.
     *
     * Legacy sent the slug implicitly on every save (Str::slug of the name),
     * which silently moved a live storefront's URL. Here the slug only moves
     * when explicitly submitted, is refused once the storefront is live, and
     * is reserved-word checked with `-1`, `-2`… de-duplication like the
     * wizard. The two refusals stay in this body (see the class docblock); the
     * de-duplication query lives in the repository and the write workflow in
     * the service.
     */
    public function update(UpdateStoreSettingsRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $data = $request->validated();
        $slug = null;

        if (! empty($data['slug'])) {
            $slug = Str::slug($data['slug']);

            if ((bool) $store->has_website && $slug !== $store->slug) {
                return $this->error(
                    'This storefront is live at its current address, so the web address is locked. Contact support if it must change.',
                    422,
                    ['slug' => ['This storefront is live at its current address, so the web address is locked.']],
                );
            }

            if (! $store->has_website) {
                Validator::make(
                    ['slug' => $slug],
                    ['slug' => ['required', 'string', 'max:255', new ReservedStoreSlug]],
                )->validate();

                $slug = $this->repository->uniqueSlug($slug, $store);
            } else {
                // Live storefront, current slug re-submitted: nothing to write,
                // exactly as the original skipped it.
                $slug = null;
            }
        }

        $this->service->update(
            $store,
            $data,
            $slug,
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        Log::info('api.management.store_settings_updated', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
        ]);

        return $this->ok(
            ['store' => (new StoreResource($store->fresh()))->resolve($request)],
            'Store updated successfully.',
        );
    }

    /**
     * Assign an existing business staff member to this store (store-context
     * counterpart of the staff `store_ids` sync). The business and
     * deactivation scoping is the FormRequest's exists rule; the pivot sync
     * itself is a single call, so it stays here.
     */
    public function assignStaff(AssignStoreStaffRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $user = $this->user($request);
        $data = $request->validated();

        $staff = User::query()->findOrFail($data['user_id']);

        $store->assignedStaff()->syncWithoutDetaching([$staff->id]);

        Log::info('api.management.store_staff_assigned', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok(
            ['staff' => (new StaffResource($staff->load('roles')))->resolve($request)],
            $staff->name.' assigned to this store.',
        );
    }

    public function removeStaff(Request $request, Store $store, User $staff): JsonResponse
    {
        $this->authorizeStore($request, $store);

        // A staff id from another business must not resolve here — legacy
        // detached without checking the business at all.
        abort_unless(
            (int) $staff->business_id === (int) $store->business_id && $staff->isStaff(),
            404,
        );

        $store->assignedStaff()->detach($staff->id);

        Log::info('api.management.store_staff_removed', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok([], $staff->name.' removed from this store.');
    }

    public function storeServiceCharge(ServiceChargeRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $data = $request->validated();

        // Service charges stay naira-decimal: POS checkout (ProcessPosSale)
        // adds this column straight onto naira order totals, and the POS read
        // endpoint returns the same unit. Delivery route fees are kobo. The
        // round() is deliberately not one of the Naira kobo converters.
        $charge = $store->serviceCharges()->create([
            'name' => $data['name'],
            'amount' => round((float) $data['amount'], 2),
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        Log::info('api.management.service_charge_created', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'service_charge_id' => $charge->id,
        ]);

        return $this->ok(
            ['service_charge' => (new ServiceChargeResource($charge))->resolve($request)],
            'Service charge saved.',
            201,
        );
    }

    /**
     * `{charge}` is resolved through the store relation so an id from another
     * store can never be edited (the legacy overloaded PUT did no such check).
     */
    public function updateServiceCharge(ServiceChargeRequest $request, Store $store, int $charge): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $serviceCharge = $store->serviceCharges()->whereKey($charge)->firstOrFail();
        $data = $request->validated();

        $serviceCharge->update([
            'name' => $data['name'],
            'amount' => round((float) $data['amount'], 2),
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? $serviceCharge->is_active,
        ]);

        Log::info('api.management.service_charge_updated', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'service_charge_id' => $serviceCharge->id,
        ]);

        return $this->ok(
            ['service_charge' => (new ServiceChargeResource($serviceCharge->fresh()))->resolve($request)],
            'Service charge updated.',
        );
    }

    public function destroyServiceCharge(Request $request, Store $store, int $charge): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $serviceCharge = $store->serviceCharges()->whereKey($charge)->firstOrFail();
        $serviceCharge->delete();

        Log::info('api.management.service_charge_deleted', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'service_charge_id' => $serviceCharge->id,
        ]);

        return $this->ok([], 'Service charge deleted.');
    }

    public function toggleServiceCharge(Request $request, Store $store, int $charge): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $serviceCharge = $store->serviceCharges()->whereKey($charge)->firstOrFail();
        $serviceCharge->update(['is_active' => ! $serviceCharge->is_active]);

        Log::info('api.management.service_charge_toggled', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'service_charge_id' => $serviceCharge->id,
        ]);

        return $this->ok(
            ['service_charge' => (new ServiceChargeResource($serviceCharge->fresh()))->resolve($request)],
            $serviceCharge->is_active ? 'Service charge enabled.' : 'Service charge disabled.',
        );
    }
}
