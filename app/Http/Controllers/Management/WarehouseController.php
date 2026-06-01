<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) return redirect()->route('management.auth.login');

        $warehouses = $vendor->warehouses()->with(['stockLocations.product', 'sections'])->latest()->get();
        return view('management.warehouses.index', compact('vendor', 'warehouses'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) return redirect()->route('management.auth.login');

        $nigerianStates = \App\Data\Nigeria::states();
        $activeStaff = User::where('role', 'staff')->where('status', 'active')
            ->get()
            ->filter(fn($s) => $s->hasPermissionTo('warehouses view'));
        return view('management.warehouses.create', compact('vendor', 'nigerianStates', 'activeStaff'));
    }

    public function store(Request $request): RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) return redirect()->route('management.auth.login');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'staff_ids' => 'nullable|array',
        ]);

        $validated['user_id'] = $vendor->id;
        $validated['business_id'] = $vendor->business_id;
        $warehouse = Warehouse::create($validated);

        if ($request->filled('staff_ids')) {
            $warehouse->assignedStaff()->sync($request->staff_ids);
        }

        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse created.');
    }

    public function show(Request $request, Warehouse $warehouse): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor || $warehouse->user_id !== $vendor->id) abort(403);

        $warehouse->load(['stockLocations.product', 'sections', 'assignedStaff']);
        $lowStockCount = $warehouse->stockLocations->filter->isLowStock()->count();

        return view('management.warehouses.show', compact('vendor', 'warehouse', 'lowStockCount'));
    }

    public function edit(Request $request, Warehouse $warehouse): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor || $warehouse->user_id !== $vendor->id) abort(403);

        $nigerianStates = \App\Data\Nigeria::states();
        $activeStaff = User::where('role', 'staff')->where('status', 'active')
            ->get()
            ->filter(fn($s) => $s->hasPermissionTo('warehouses view'));
        $warehouse->load('assignedStaff');
        return view('management.warehouses.edit', compact('vendor', 'warehouse', 'nigerianStates', 'activeStaff'));
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor || $warehouse->user_id !== $vendor->id) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'staff_ids' => 'nullable|array',
        ]);

        $warehouse->update($validated);

        if ($request->has('staff_ids')) {
            $warehouse->assignedStaff()->sync($request->staff_ids ?? []);
        }
        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor || $warehouse->user_id !== $vendor->id) abort(403);

        $warehouse->delete();
        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse deleted.');
    }
}
