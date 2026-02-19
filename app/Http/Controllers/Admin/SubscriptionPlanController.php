<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionPlanController extends Controller
{
    /**
     * Display a listing of the subscription plans.
     */
    public function index()
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->paginate(15);
        return view('admin.subscription_fee.index', compact('plans'));
    }

    /**
     * Store a newly created subscription plan.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|max:3',
            'interval' => 'required|string|in:daily,weekly,monthly,yearly',
            'interval_count' => 'required|integer|min:1',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_trial' => 'boolean',
            'trial_days' => 'nullable|integer|min:1',
            'features' => 'nullable|string',
            'sort_order' => 'integer|min:0',
        ]);

        // Parse features from textarea (one per line)
        if (!empty($validated['features'])) {
            $validated['features'] = array_values(array_filter(
                array_map('trim', explode("\n", $validated['features']))
            ));
        } else {
            $validated['features'] = [];
        }

        // If setting as default, unset other defaults
        if (!empty($validated['is_default'])) {
            SubscriptionPlan::where('is_default', true)->update(['is_default' => false]);
        }

        $plan = SubscriptionPlan::create($validated);

        Log::info('Subscription plan created', [
            'plan_id' => $plan->id,
            'name' => $plan->name,
            'admin_id' => auth()->id(),
        ]);

        return redirect()->route('admin.subscription-plans.index')
            ->with('success', 'Subscription plan created successfully.');
    }

    /**
     * Update the specified subscription plan in storage.
     */
    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|max:3',
            'interval' => 'required|string|in:daily,weekly,monthly,yearly',
            'interval_count' => 'required|integer|min:1',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_trial' => 'boolean',
            'trial_days' => 'nullable|integer|min:1',
            'features' => 'nullable|string',
            'sort_order' => 'integer|min:0',
        ]);

        // Parse features from textarea
        if (!empty($validated['features'])) {
            $validated['features'] = array_values(array_filter(
                array_map('trim', explode("\n", $validated['features']))
            ));
        } else {
            $validated['features'] = [];
        }

        // If setting as default, unset other defaults
        if (!empty($validated['is_default'])) {
            SubscriptionPlan::where('is_default', true)
                ->where('id', '!=', $subscriptionPlan->id)
                ->update(['is_default' => false]);
        }

        $subscriptionPlan->update($validated);

        Log::info('Subscription plan updated', [
            'plan_id' => $subscriptionPlan->id,
            'name' => $subscriptionPlan->name,
            'amount' => $subscriptionPlan->amount,
            'admin_id' => auth()->id(),
        ]);

        return redirect()->route('admin.subscription-plans.index')
            ->with('success', 'Subscription plan updated successfully.');
    }

    /**
     * Remove the specified subscription plan.
     */
    public function destroy(SubscriptionPlan $subscriptionPlan)
    {
        // Prevent deleting if vendors are actively subscribed
        if ($subscriptionPlan->vendorSubscriptions()->where('status', 'active')->exists()) {
            return redirect()->route('admin.subscription-plans.index')
                ->with('error', 'Cannot delete a plan with active subscriptions. Deactivate it instead.');
        }

        $name = $subscriptionPlan->name;
        $subscriptionPlan->delete();

        Log::info('Subscription plan deleted', [
            'name' => $name,
            'admin_id' => auth()->id(),
        ]);

        return redirect()->route('admin.subscription-plans.index')
            ->with('success', "Plan \"{$name}\" deleted successfully.");
    }
}
