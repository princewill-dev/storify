<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $vendor = $request->user();
        $locations = $vendor->locations()->withCount('warehouses')->latest()->get();
        $nigerianStates = \App\Data\Nigeria::states();
        $nigerianCities = \App\Data\Nigeria::topCities();
        return view('management.locations.index', compact('vendor', 'locations', 'nigerianStates', 'nigerianCities'));
    }

    public function create(Request $request): View
    {
        $vendor = $request->user();
        $nigerianStates = \App\Data\Nigeria::states();
        $nigerianCities = \App\Data\Nigeria::topCities();
        return view('management.locations.create', compact('vendor', 'nigerianStates', 'nigerianCities'));
    }

    public function store(Request $request): RedirectResponse
    {
        $vendor = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);
        $validated['user_id'] = $vendor->id;
        Location::create($validated);
        return redirect()->route('management.locations.index')->with('success', 'Location created.');
    }

    public function show(Request $request, Location $location): View
    {
        $vendor = $request->user();
        if ($location->user_id !== $vendor->id) abort(403);
        $location->load('warehouses.sections');
        return view('management.locations.show', compact('vendor', 'location'));
    }

    public function edit(Request $request, Location $location): View
    {
        $vendor = $request->user();
        if ($location->user_id !== $vendor->id) abort(403);
        $nigerianStates = \App\Data\Nigeria::states();
        $nigerianCities = \App\Data\Nigeria::topCities();
        return view('management.locations.edit', compact('vendor', 'location', 'nigerianStates', 'nigerianCities'));
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $vendor = $request->user();
        if ($location->user_id !== $vendor->id) abort(403);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);
        $location->update($validated);
        return redirect()->route('management.locations.index')->with('success', 'Location updated.');
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        $vendor = $request->user();
        if ($location->user_id !== $vendor->id) abort(403);
        $location->delete();
        return redirect()->route('management.locations.index')->with('success', 'Location deleted.');
    }
}
