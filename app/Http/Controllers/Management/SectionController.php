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
        $vendor = $request->user();
        if ($warehouse->user_id !== $vendor->id) abort(403);
        $sections = $warehouse->sections()->withCount('products')->latest()->get();
        return view('management.sections.index', compact('vendor', 'warehouse', 'sections'));
    }

    public function create(Request $request, Warehouse $warehouse): View
    {
        $vendor = $request->user();
        if ($warehouse->user_id !== $vendor->id) abort(403);
        return view('management.sections.create', compact('vendor', 'warehouse'));
    }

    public function store(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $vendor = $request->user();
        if ($warehouse->user_id !== $vendor->id) abort(403);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);
        $validated['warehouse_id'] = $warehouse->id;
        $validated['business_id'] = $vendor->business_id;
        Section::create($validated);
        return redirect()->route('management.sections.index', $warehouse)->with('success', 'Section created.');
    }

    public function show(Request $request, Warehouse $warehouse, Section $section): View
    {
        $vendor = $request->user();
        if ($warehouse->user_id !== $vendor->id) abort(403);
        $section->load('products.store');
        $products = $section->products()->with('store')->latest()->paginate(50);
        $stats = [
            'count' => $section->products()->count(),
            'active' => $section->products()->where('status', 'active')->count(),
            'value' => $section->products()->sum('amount'),
            'outOfStock' => $section->products()->where('quantity', '<=', 0)->count(),
        ];
        return view('management.sections.show', compact('vendor', 'warehouse', 'section', 'products', 'stats'));
    }

    public function edit(Request $request, Warehouse $warehouse, Section $section): View
    {
        $vendor = $request->user();
        if ($warehouse->user_id !== $vendor->id) abort(403);
        return view('management.sections.edit', compact('vendor', 'warehouse', 'section'));
    }

    public function update(Request $request, Warehouse $warehouse, Section $section): RedirectResponse
    {
        $vendor = $request->user();
        if ($warehouse->user_id !== $vendor->id) abort(403);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);
        $section->update($validated);
        return redirect()->route('management.sections.index', $warehouse)->with('success', 'Section updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse, Section $section): RedirectResponse
    {
        $vendor = $request->user();
        if ($warehouse->user_id !== $vendor->id) abort(403);
        if ($section->products()->count() > 0) {
            return back()->with('error', 'Cannot delete a section with products.');
        }
        $section->delete();
        return redirect()->route('management.sections.index', $warehouse)->with('success', 'Section deleted.');
    }
}
