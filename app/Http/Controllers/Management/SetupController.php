<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $vendor = $request->user();

        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        if (!$vendor->is_verified) {
            return redirect()->route('management.auth.verify-otp', ['vendor' => $vendor])
                ->with('warning', 'Please verify your email first.');
        }

        if ($vendor->business_id) {
            return redirect()->route('management.dashboard');
        }

        $countries = \App\Data\Countries::business();

        return view('auth.business.setup', compact('vendor', 'countries'));
    }

    public function store(Request $request): RedirectResponse
    {
        $vendor = $request->user();

        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'phone' => 'nullable|string|max:50',
            'business_location' => 'nullable|string|max:100',
        ]);

        $business = Business::create([
            'user_id' => $vendor->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'business_location' => $validated['business_location'] ?? null,
            'status' => 'active',
        ]);

        $vendor->update(['business_id' => $business->id]);

        $seeder = new \Database\Seeders\SpatiePermissionSeeder();
        $seeder->createRolesForBusiness($business);

        return redirect()->route('management.plans.index')
            ->with('success', 'Your business is set up! Now choose a plan to get started.');
    }
}
