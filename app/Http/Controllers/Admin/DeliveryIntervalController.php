<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeliveryIntervalController extends Controller
{
    /**
     * Display listing of delivery intervals
     */
    public function index()
    {
        $intervals = DeliveryInterval::orderBy('sort_order')->get();
        
        return view('admin.settings.delivery_intervals', compact('intervals'));
    }

    /**
     * Store a new delivery interval
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'days_count' => 'required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $slug = Str::slug($request->name);

        // Check if slug already exists
        if (DeliveryInterval::where('slug', $slug)->exists()) {
            return back()->with('error', 'An interval with this name already exists.');
        }

        DeliveryInterval::create([
            'name' => $request->name,
            'slug' => $slug,
            'days_count' => $request->days_count,
            'is_active' => true,
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return back()->with('success', 'Delivery interval created successfully!');
    }

    /**
     * Update delivery interval
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'days_count' => 'required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $interval = DeliveryInterval::findOrFail($id);
        $slug = Str::slug($request->name);

        // Check if slug already exists (excluding current interval)
        if (DeliveryInterval::where('slug', $slug)->where('id', '!=', $id)->exists()) {
            return back()->with('error', 'An interval with this name already exists.');
        }

        $interval->update([
            'name' => $request->name,
            'slug' => $slug,
            'days_count' => $request->days_count,
            'sort_order' => $request->sort_order ?? $interval->sort_order,
        ]);

        return back()->with('success', 'Delivery interval updated successfully!');
    }

    /**
     * Toggle active status
     */
    public function toggle($id)
    {
        $interval = DeliveryInterval::findOrFail($id);
        
        $interval->update([
            'is_active' => !$interval->is_active,
        ]);

        $status = $interval->is_active ? 'activated' : 'deactivated';
        
        return back()->with('success', "Delivery interval {$status} successfully!");
    }

    /**
     * Delete delivery interval
     */
    public function destroy($id)
    {
        $interval = DeliveryInterval::findOrFail($id);

        // Check if interval is being used
        if ($interval->familyPackOrders()->exists()) {
            return back()->with('error', 'Cannot delete interval that is being used by family pack orders.');
        }

        $interval->delete();

        return back()->with('success', 'Delivery interval deleted successfully!');
    }
}
