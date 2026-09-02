<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use App\Services\StoreAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class StoreSettingsController extends Controller
{
    public function __construct(private readonly StoreAccessService $access) {}

    public function show(Request $request, Store $store): View
    {
        $this->access->authorize($request->user(), $store);
        $store->load(['ownershipType', 'businessType', 'business', 'banks', 'deliveryRoutes', 'assignedStaff.roles']);
        $availableStaff = User::query()
            ->where('business_id', $request->user()->business_id)
            ->where('role', 'staff')
            ->where('status', 'active')
            ->whereNotIn('id', $store->assignedStaff->pluck('id'))
            ->with('roles')
            ->get(['id', 'name', 'email']);
        $user = $request->user();
        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name, 'url' => route('management.stores.show', $store)],
            ['label' => 'Settings'],
        ];

        return view('management.stores.settings', compact('user', 'store', 'availableStaff', 'breadcrumbs'));
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);

        if ($request->filled('service_charge_name')) {
            return $this->saveServiceCharge($request, $store);
        }
        if ($request->filled('delete_service_charge_id')) {
            $store->serviceCharges()->whereKey($request->integer('delete_service_charge_id'))->delete();

            return back()->with('success', 'Service charge deleted.');
        }
        if ($request->filled('toggle_service_charge_id')) {
            $charge = $store->serviceCharges()->findOrFail($request->integer('toggle_service_charge_id'));
            $charge->update(['is_active' => ! $charge->is_active]);

            return back()->with('success', 'Service charge updated.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);
        $data['slug'] = Str::slug($data['slug'] ?: $data['name']);
        $baseSlug = $data['slug'];
        $counter = 1;
        while (Store::where('slug', $data['slug'])->where('id', '!=', $store->id)->exists()) {
            $data['slug'] = $baseSlug.'-'.$counter++;
        }

        $oldLogo = $store->logo_path;
        $newLogo = $request->file('logo')?->store('stores/logos', 'public');
        unset($data['logo']);
        if ($newLogo) {
            $data['logo_path'] = $newLogo;
        }

        try {
            $store->update($data);
        } catch (\Throwable $e) {
            if ($newLogo) {
                Storage::disk('public')->delete($newLogo);
            }
            throw $e;
        }

        if ($newLogo && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
        }

        return $request->filled('redirect_to')
            ? redirect($request->string('redirect_to')->toString())->with('success', 'Store updated successfully.')
            : redirect()->route('management.stores.settings', $store)->with('success', 'Store updated successfully.');
    }

    public function assignStaff(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $staff = User::query()
            ->where('business_id', $request->user()->business_id)
            ->where('role', 'staff')
            ->findOrFail($data['user_id']);
        $store->assignedStaff()->syncWithoutDetaching([$staff->id]);

        return back()->with('success', $staff->name.' assigned to this store.');
    }

    public function removeStaff(Request $request, Store $store, User $user): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        abort_unless((int) $user->business_id === (int) $store->business_id && $user->isStaff(), 404);
        $store->assignedStaff()->detach($user->id);

        return back()->with('success', $user->name.' removed from this store.');
    }

    public function assignBank(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        $data = $request->validate(['store_bank_id' => ['required', 'exists:store_banks,id']]);
        $bank = StoreBank::where('business_id', $store->business_id)->findOrFail($data['store_bank_id']);
        $store->assignedBanks()->syncWithoutDetaching([$bank->id => ['is_active' => true]]);

        return back()->with('success', $bank->bank_name.' assigned to this store.');
    }

    public function removeBank(Request $request, Store $store, StoreBank $bank): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        abort_unless((int) $bank->business_id === (int) $store->business_id, 404);
        $store->assignedBanks()->detach($bank->id);

        return back()->with('success', $bank->bank_name.' removed from this store.');
    }

    public function enablePos(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        $store->update(['pos_enabled' => true]);

        return back()->with('success', 'POS terminal enabled for this store.');
    }

    private function saveServiceCharge(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate([
            'service_charge_name' => ['required', 'string', 'max:255'],
            'service_charge_amount' => ['required', 'numeric', 'min:0'],
            'service_charge_description' => ['nullable', 'string', 'max:500'],
            'service_charge_id' => ['nullable', 'integer'],
        ]);
        $attributes = [
            'name' => $data['service_charge_name'],
            'amount' => $data['service_charge_amount'],
            'description' => $data['service_charge_description'] ?? null,
        ];

        $data['service_charge_id'] ?? null
            ? $store->serviceCharges()->whereKey($data['service_charge_id'])->update($attributes)
            : $store->serviceCharges()->create($attributes);

        return back()->with('success', 'Service charge saved.');
    }
}
