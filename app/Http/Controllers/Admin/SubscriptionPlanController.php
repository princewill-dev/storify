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
        $plans = SubscriptionPlan::paginate(15);
        return view('admin.subscription_fee.index', compact('plans'));
    }

    /**
     * Update the specified subscription plan in storage.
     */
    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

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
}
