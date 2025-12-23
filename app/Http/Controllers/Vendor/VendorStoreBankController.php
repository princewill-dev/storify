<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorStoreBankController extends Controller
{
    /**
     * Store a new bank account for the store.
     */
    public function store(Request $request, Vendor $vendor, Store $store): RedirectResponse
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'bank_code' => 'required|string|max:50',
            'account_number' => 'required|string|max:20',
            'account_name' => 'required|string|max:255',
            'is_primary' => 'boolean',
        ]);

        $isPrimary = $request->has('is_primary');

        DB::transaction(function () use ($store, $validated, $isPrimary) {
            if ($isPrimary) {
                $store->banks()->update(['is_primary' => false]);
            }

            // If no banks exist, the first one should be primary
            if ($store->banks()->count() === 0) {
                $isPrimary = true;
            }

            $store->banks()->create(array_merge($validated, ['is_primary' => $isPrimary, 'is_verified' => true]));
        });

        return redirect()->back()->with('success', 'Bank account added successfully.');
    }

    /**
     * Update the specified bank account.
     */
    public function update(Request $request, Vendor $vendor, Store $store, StoreBank $bank): RedirectResponse
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'bank_code' => 'required|string|max:50',
            'account_number' => 'required|string|max:20',
            'account_name' => 'required|string|max:255',
            'is_primary' => 'boolean',
        ]);

        $isPrimary = $request->has('is_primary');

        DB::transaction(function () use ($store, $bank, $validated, $isPrimary) {
            if ($isPrimary) {
                $store->banks()->where('id', '!=', $bank->id)->update(['is_primary' => false]);
            }

            $bank->update(array_merge($validated, ['is_primary' => $isPrimary]));
        });

        return redirect()->back()->with('success', 'Bank account updated successfully.');
    }

    /**
     * Remove the specified bank account.
     */
    public function destroy(Vendor $vendor, Store $store, StoreBank $bank): RedirectResponse
    {
        if ($bank->is_primary) {
            return redirect()->back()->with('error', 'Cannot delete the primary bank account. Set another account as primary first.');
        }

        $bank->delete();

        return redirect()->back()->with('success', 'Bank account deleted successfully.');
    }

    /**
     * Set the specified bank account as primary.
     */
    public function setPrimary(Vendor $vendor, Store $store, StoreBank $bank): RedirectResponse
    {
        DB::transaction(function () use ($store, $bank) {
            $store->banks()->update(['is_primary' => false]);
            $bank->update(['is_primary' => true]);
        });

        return redirect()->back()->with('success', 'Primary bank account updated.');
    }
}
