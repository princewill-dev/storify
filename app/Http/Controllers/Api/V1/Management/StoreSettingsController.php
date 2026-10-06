<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\ServiceCharge;
use App\Models\Store;
use App\Models\User;
use App\Rules\ReservedStoreSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreSettingsController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * Everything the settings workspace renders: the detail cards, service
     * charges, delivery routes, assigned/available staff, read-only bank
     * accounts, POS session state and storefront status.
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        return $this->ok($this->settingsPayload($store));
    }

    /**
     * Store details, branding and socials.
     *
     * Legacy sent the slug implicitly on every save (Str::slug of the name),
     * which silently moved a live storefront's URL. Here the slug only moves
     * when explicitly submitted, is refused once the storefront is live, and
     * is reserved-word checked with `-1`, `-2`… de-duplication like the wizard.
     */
    public function update(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $user = $this->user($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        // Partial-update semantics: only the fields present in the request are
        // touched, so saving the Socials card cannot blank the address the way
        // a full-attribute overwrite would. Sending an empty string clears a
        // nullable field (ConvertEmptyStringsToNull turns it into null).
        $attributes = [];

        foreach ([
            'name',
            'description',
            'support_email',
            'support_phone',
            'address',
            'instagram_url',
            'facebook_url',
            'twitter_url',
            'tiktok_url',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

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

                $attributes['slug'] = $this->uniqueSlug($slug, $store);
            }
        }

        $oldLogo = $store->logo_path;
        $newLogo = $request->file('logo')?->store('stores/logos', 'public');
        $removeLogo = $request->boolean('remove_logo');

        if ($newLogo) {
            $attributes['logo_path'] = $newLogo;
        } elseif ($removeLogo) {
            $attributes['logo_path'] = null;
        }

        try {
            DB::transaction(fn () => $store->update($attributes));
        } catch (\Throwable $e) {
            // The file exists before the row does; clean it up when the save
            // fails so a rejected update never leaves an orphan on disk.
            if ($newLogo) {
                Storage::disk('public')->delete($newLogo);
            }

            throw $e;
        }

        // Only delete the previous logo after the row carrying the new path is
        // safely persisted — legacy did the same, and it is the safe order.
        if (($newLogo || $removeLogo) && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
        }

        Log::info('api.management.store_settings_updated', [
            'user_id' => $user->id,
            'store_id' => $store->id,
        ]);

        return $this->ok(['store' => $this->storePayload($store->fresh())], 'Store updated successfully.');
    }

    /**
     * Assign an existing business staff member to this store (store-context
     * counterpart of the staff `store_ids` sync).
     */
    public function assignStaff(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $user = $this->user($request);

        $data = $request->validate([
            'user_id' => [
                'required',
                'integer',
                // Deactivated staff (status = deleted, per WS-20's soft
                // deactivation) are no longer assignable anywhere.
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('business_id', $user->business_id)
                    ->where('role', 'staff')
                    ->where('status', '!=', 'deleted')),
            ],
        ]);

        $staff = User::query()->findOrFail($data['user_id']);

        $store->assignedStaff()->syncWithoutDetaching([$staff->id]);

        Log::info('api.management.store_staff_assigned', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'staff_id' => $staff->id,
        ]);

        return $this->ok(
            ['staff' => $this->staffPayload($staff->load('roles'))],
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

    public function storeServiceCharge(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $data = $this->validateServiceCharge($request);

        // Service charges stay naira-decimal: POS checkout (ProcessPosSale)
        // adds this column straight onto naira order totals, and the POS read
        // endpoint returns the same unit. Delivery route fees are kobo.
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

        return $this->ok(['service_charge' => $this->chargePayload($charge)], 'Service charge saved.', 201);
    }

    /**
     * `{charge}` is resolved through the store relation so an id from another
     * store can never be edited (the legacy overloaded PUT did no such check).
     */
    public function updateServiceCharge(Request $request, Store $store, int $charge): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $serviceCharge = $store->serviceCharges()->whereKey($charge)->firstOrFail();
        $data = $this->validateServiceCharge($request);

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

        return $this->ok(['service_charge' => $this->chargePayload($serviceCharge->fresh())], 'Service charge updated.');
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
            ['service_charge' => $this->chargePayload($serviceCharge->fresh())],
            $serviceCharge->is_active ? 'Service charge enabled.' : 'Service charge disabled.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(Store $store): array
    {
        $store->load(['businessType', 'serviceCharges', 'deliveryRoutes', 'assignedStaff.roles', 'assignedBanks']);
        $store->loadMissing('activePosSession.staff');

        $assignedIds = $store->assignedStaff->pluck('id');

        $available = User::query()
            ->where('business_id', $store->business_id)
            ->where('role', 'staff')
            ->where('status', '!=', 'deleted')
            ->whereNotIn('id', $assignedIds)
            ->with('roles')
            ->orderBy('name')
            ->get(['id', 'account_code', 'name', 'email']);

        $session = $store->activePosSession;

        return [
            'store' => $this->storePayload($store),
            'service_charges' => $store->serviceCharges
                ->sortBy('name')
                ->values()
                ->map(fn (ServiceCharge $charge) => $this->chargePayload($charge))
                ->all(),
            'delivery_routes' => $store->deliveryRoutes
                ->sortBy('state')
                ->values()
                ->map(fn ($route) => [
                    'id' => $route->id,
                    'country' => $route->country,
                    'state' => $route->state,
                    'area' => $route->area,
                    'fee' => (int) $route->fee,
                    'delivery_days' => (int) $route->delivery_days,
                    'active' => (bool) $route->active,
                ])->all(),
            'staff' => [
                'assigned' => $store->assignedStaff
                    ->map(fn (User $member) => $this->staffPayload($member))
                    ->values()
                    ->all(),
                'available' => $available
                    ->map(fn (User $member) => $this->staffPayload($member))
                    ->values()
                    ->all(),
            ],
            'banks' => $store->assignedBanks->map(fn ($bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                'masked_account_number' => $bank->masked_account_number,
                'is_primary' => (bool) $bank->is_primary,
                'is_verified' => (bool) $bank->is_verified,
            ])->values()->all(),
            'pos' => [
                'enabled' => (bool) $store->pos_enabled,
                'active_session' => $session ? [
                    'id' => $session->id,
                    'session_code' => $session->session_code,
                    'opened_by' => $session->staff?->name,
                    'opened_at' => $session->opened_at?->toISOString(),
                    'opening_balance' => (int) $session->opening_balance,
                ] : null,
            ],
            'storefront' => [
                'enabled' => (bool) $store->has_website,
                'url' => $store->has_website && $store->slug
                    ? 'https://'.$store->slug.'.'.config('app.main_domain')
                    : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'pos_enabled' => (bool) $store->pos_enabled,
            'description' => $store->description,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
            'address' => $store->address,
            'instagram_url' => $store->instagram_url,
            'facebook_url' => $store->facebook_url,
            'twitter_url' => $store->twitter_url,
            'tiktok_url' => $store->tiktok_url,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            'business' => [
                'id' => $store->business_id,
                'name' => $store->business?->name,
                'type' => $store->businessType?->name,
            ],
            'created_at' => $store->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffPayload(User $staff): array
    {
        return [
            'id' => $staff->id,
            'account_code' => $staff->account_code,
            'name' => $staff->name,
            'email' => $staff->email,
            'roles' => $staff->relationLoaded('roles') ? $staff->roles->pluck('name')->values()->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(ServiceCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'name' => $charge->name,
            'amount' => round((float) $charge->amount, 2),
            'description' => $charge->description,
            'is_active' => (bool) $charge->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateServiceCharge(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function uniqueSlug(string $base, Store $store): string
    {
        $slug = $base;
        $counter = 1;

        while (Store::where('slug', $slug)->whereKeyNot($store->getKey())->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
