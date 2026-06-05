<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionController extends Controller
{
    public function index(Request $request, Warehouse $warehouse): View
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);
        $sections = $warehouse->sections()->withCount('products')->where('status', '!=', 'deleted')->latest()->get();
        return view('management.sections.index', compact('user', 'warehouse', 'sections'));
    }

    public function create(Request $request, Warehouse $warehouse): View
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);
        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Sections', 'url' => route('management.warehouses.index')],
            ['label' => 'Create'],
        ];

        return view('management.sections.create', compact('user', 'warehouse', 'breadcrumbs'));
    }

    public function store(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);
        $validated['warehouse_id'] = $warehouse->id;
        $validated['business_id'] = $user->business_id;
        $validated['status'] = $request->boolean('is_active') ? 'active' : 'inactive';
        unset($validated['is_active']);
        Section::create($validated);
        return redirect()->route('management.sections.index', $warehouse)->with('success', 'Section created.');
    }

    public function show(Request $request, Warehouse $warehouse, Section $section): View
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);
        $section->load('products.store');
        $products = $section->products()->with('store')->latest()->paginate(50);
        $stats = [
            'count' => $section->products()->count(),
            'active' => $section->products()->where('status', 'active')->count(),
            'value' => $section->products()->sum('amount'),
            'outOfStock' => $section->products()->where('quantity', '<=', 0)->count(),
        ];
        return view('management.sections.show', compact('user', 'warehouse', 'section', 'products', 'stats'));
    }

    public function edit(Request $request, Warehouse $warehouse, Section $section): View
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);
        return view('management.sections.edit', compact('user', 'warehouse', 'section'));
    }

    public function update(Request $request, Warehouse $warehouse, Section $section): RedirectResponse
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);
        $validated['status'] = $request->boolean('is_active') ? 'active' : 'inactive';
        unset($validated['is_active']);
        $section->update($validated);
        return redirect()->route('management.sections.index', $warehouse)->with('success', 'Section updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse, Section $section): RedirectResponse
    {
        $user = $request->user();
        if ($user->isStaff()) {
            if (!$user->assignedWarehouses()->where('warehouses.id', $warehouse->id)->exists()) abort(403);
        } elseif ($warehouse->user_id !== $user->id) abort(403);
        if ($section->products()->count() > 0) {
            return back()->with('error', 'Cannot delete a section with products.');
        }
        $section->update(['status' => 'deleted']);
        return redirect()->route('management.sections.index', $warehouse)->with('success', 'Section deleted.');
    }
}
