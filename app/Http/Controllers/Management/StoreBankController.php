<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreBankController extends Controller
{
    /**
     * Store a new bank account for the store.
     */
    public function store(Request $request, Vendor $vendor, Store $store): RedirectResponse
    {
        \Log::info('[Bank Store] Request received', [
            'user_id' => $vendor->id,
            'store_id' => $store->id,
            'request_data' => $request->all()
        ]);

        try {
            $validated = $request->validate([
                'bank_name' => 'required|string|max:255',
                'bank_code' => 'required|string|max:50',
                'account_number' => 'required|string|max:20',
                'account_name' => 'required|string|max:255',
                'is_primary' => 'nullable|in:on,1,true',
            ]);
            
            \Log::info('[Bank Store] Validation passed', ['validated' => $validated]);

            // Convert checkbox value to boolean
            $isPrimary = $request->filled('is_primary');
            \Log::info('[Bank Store] Is primary checkbox', ['is_primary' => $isPrimary, 'raw_value' => $request->input('is_primary')]);

            DB::transaction(function () use ($store, $validated, $isPrimary) {
                \Log::info('[Bank Store] Starting DB transaction');
                
                if ($isPrimary) {
                    $updated = $store->banks()->update(['is_primary' => false]);
                    \Log::info('[Bank Store] Reset existing primary banks', ['count' => $updated]);
                }

                // If no banks exist, the first one should be primary
                $bankCount = $store->banks()->count();
                \Log::info('[Bank Store] Existing bank count', ['count' => $bankCount]);
                
                if ($bankCount === 0) {
                    $isPrimary = true;
                    \Log::info('[Bank Store] First bank, setting as primary');
                }

                $bankData = array_merge($validated, ['is_primary' => $isPrimary, 'is_verified' => true]);
                \Log::info('[Bank Store] Creating bank with data', $bankData);
                
                $bank = $store->banks()->create($bankData);
                \Log::info('[Bank Store] Bank created successfully', ['bank_id' => $bank->id]);
            });

            \Log::info('[Bank Store] Transaction committed successfully');
            return redirect()->back()->with('success', 'Bank account added successfully.');
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('[Bank Store] Validation failed', [
                'errors' => $e->errors()
            ]);
            return redirect()->back()->withErrors($e->errors())->withInput();
            
        } catch (\Exception $e) {
            \Log::error('[Bank Store] Exception occurred', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Failed to add bank account: ' . $e->getMessage());
        }
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
