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
        $user = $request->user();
        $locations = $user->locations()->withCount('warehouses')->latest()->get();
        $nigerianStates = \App\Data\Nigeria::states();
        $nigerianCities = \App\Data\Nigeria::topCities();
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Locations']];
        return view('management.locations.index', compact('user', 'locations', 'nigerianStates', 'nigerianCities', 'breadcrumbs'));
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $nigerianStates = \App\Data\Nigeria::states();
        $nigerianCities = \App\Data\Nigeria::topCities();
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Locations', 'url' => route('management.locations.index')], ['label' => 'Create']];
        return view('management.locations.create', compact('user', 'nigerianStates', 'nigerianCities', 'breadcrumbs'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);
        $validated['user_id'] = $user->id;
        Location::create($validated);
        return redirect()->route('management.locations.index')->with('success', 'Location created.');
    }

    public function show(Request $request, Location $location): View
    {
        $user = $request->user();
        if ($location->user_id !== $user->id) abort(403);
        $location->load('warehouses.sections');
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Locations', 'url' => route('management.locations.index')], ['label' => $location->name ?? 'Location']];
        return view('management.locations.show', compact('user', 'location', 'breadcrumbs'));
    }

    public function edit(Request $request, Location $location): View
    {
        $user = $request->user();
        if ($location->user_id !== $user->id) abort(403);
        $nigerianStates = \App\Data\Nigeria::states();
        $nigerianCities = \App\Data\Nigeria::topCities();
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Locations', 'url' => route('management.locations.index')], ['label' => $location->name ?? 'Location', 'url' => route('management.locations.show', $location)], ['label' => 'Edit']];
        return view('management.locations.edit', compact('user', 'location', 'nigerianStates', 'nigerianCities', 'breadcrumbs'));
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $user = $request->user();
        if ($location->user_id !== $user->id) abort(403);
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
        $user = $request->user();
        if ($location->user_id !== $user->id) abort(403);
        $location->delete();
        return redirect()->route('management.locations.index')->with('success', 'Location deleted.');
    }
}
