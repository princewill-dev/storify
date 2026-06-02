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
        $user = $request->user();
        if (!$user) return redirect()->route('management.auth.login');

        $warehouses = ($user->isStaff() ? $user->assignedWarehouses() : $user->warehouses())
            ->with(['stockLocations.product', 'sections', 'assignedStaff'])->latest()->get();
        return view('management.warehouses.index', compact('user', 'warehouses'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('management.auth.login');

        $nigerianStates = \App\Data\Nigeria::states();
        $activeStaff = User::where('role', 'staff')->where('status', 'active')
            ->get()
            ->filter(fn($s) => $s->hasPermissionTo('warehouses view'));
        return view('management.warehouses.create', compact('user', 'nigerianStates', 'activeStaff'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('management.auth.login');

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

        $validated['user_id'] = $user->id;
        $validated['business_id'] = $user->business_id;
        $warehouse = Warehouse::create($validated);

        if ($request->filled('staff_ids')) {
            $staffIds = array_filter($request->staff_ids);
            if (!empty($staffIds)) {
                $warehouse->assignedStaff()->sync($staffIds);
            }
        }

        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse created.');
    }

    public function show(Request $request, Warehouse $warehouse): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) abort(403);
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $warehouse->load(['stockLocations.product', 'sections', 'assignedStaff']);
        $lowStockCount = $warehouse->stockLocations->filter->isLowStock()->count();

        return view('management.warehouses.show', compact('user', 'warehouse', 'lowStockCount'));
    }

    public function edit(Request $request, Warehouse $warehouse): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) abort(403);
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $nigerianStates = \App\Data\Nigeria::states();
        $activeStaff = User::where('role', 'staff')->where('status', 'active')
            ->get()
            ->filter(fn($s) => $s->hasPermissionTo('warehouses view'));
        $warehouse->load('assignedStaff');
        return view('management.warehouses.edit', compact('user', 'warehouse', 'nigerianStates', 'activeStaff'));
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if (!$user) abort(403);
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

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
            $staffIds = array_filter($request->staff_ids ?? []);
            $warehouse->assignedStaff()->sync($staffIds);
        }
        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if (!$user) abort(403);
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);

        $warehouse->delete();
        return redirect()->route('management.warehouses.index')->with('success', 'Warehouse deleted.');
    }
}
