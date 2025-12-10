<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BankAccountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $bankAccounts = BankAccount::orderBy('sort_order')->paginate(15);
        return view('admin.bank-accounts.index', compact('bankAccounts'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.bank-accounts.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('bank-logos', 'public');
        }

        $bankAccount = BankAccount::create($validated);

        Log::info('Bank account created', [
            'bank_account_id' => $bankAccount->id,
            'bank_name' => $bankAccount->bank_name,
        ]);

        return redirect()->route('admin.bank-accounts.index')
            ->with('success', 'Bank account created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(BankAccount $bankAccount)
    {
        return view('admin.bank-accounts.show', compact('bankAccount'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BankAccount $bankAccount)
    {
        return view('admin.bank-accounts.edit', compact('bankAccount'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BankAccount $bankAccount)
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        if ($request->hasFile('logo')) {
            // Delete old logo if exists
            if ($bankAccount->logo) {
                Storage::disk('public')->delete($bankAccount->logo);
            }
            $validated['logo'] = $request->file('logo')->store('bank-logos', 'public');
        }

        $bankAccount->update($validated);

        Log::info('Bank account updated', [
            'bank_account_id' => $bankAccount->id,
            'bank_name' => $bankAccount->bank_name,
        ]);

        return redirect()->route('admin.bank-accounts.index')
            ->with('success', 'Bank account updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BankAccount $bankAccount)
    {
        // Delete logo if exists
        if ($bankAccount->logo) {
            Storage::disk('public')->delete($bankAccount->logo);
        }

        $bankAccount->delete();

        Log::info('Bank account deleted', [
            'bank_account_id' => $bankAccount->id,
            'bank_name' => $bankAccount->bank_name,
        ]);

        return redirect()->route('admin.bank-accounts.index')
            ->with('success', 'Bank account deleted successfully.');
    }

    /**
     * Toggle active status
     */
    public function toggleActive(BankAccount $bankAccount)
    {
        $bankAccount->update(['is_active' => !$bankAccount->is_active]);

        return redirect()->back()
            ->with('success', 'Bank account status updated successfully.');
    }
}
