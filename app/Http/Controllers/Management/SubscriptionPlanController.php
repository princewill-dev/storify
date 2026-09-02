<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionTrialSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

final class SubscriptionPlanController extends Controller
{
    public function __construct(private readonly SubscriptionTrialSettings $trialSettings) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('management.auth.login');
        }

        $subscription = $user->business?->activeSubscription()->first();
        $plans = SubscriptionPlan::active()->where('is_trial', false)->orderBy('sort_order')->get();
        $trial = $this->trialSettings->get();
        [$monthlyPlans, $yearlyPlans, $otherPlans] = [
            $plans->where('interval', 'monthly')->values(),
            $plans->where('interval', 'yearly')->values(),
            $plans->whereNotIn('interval', ['monthly', 'yearly'])->values(),
        ];

        return view('management.subscription.plan', [
            'user' => $user,
            'subscription' => $subscription,
            'payments' => $subscription
                ? Payment::where('subscription_id', $subscription->id)->latest()->take(20)->get()
                : collect(),
            'plans' => $plans,
            'monthlyPlans' => $monthlyPlans,
            'yearlyPlans' => $yearlyPlans,
            'otherPlans' => $otherPlans,
            'yearlySavingsPercent' => $this->yearlySavings($monthlyPlans, $yearlyPlans),
            'trialEnabled' => $trial['enabled'],
            'trialDays' => $trial['days'],
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('management.dashboard')],
                ['label' => 'Subscription'],
            ],
        ]);
    }

    public function change(Request $request): RedirectResponse
    {
        $subscription = $request->user()?->business?->activeSubscription()->first();
        if (! $subscription) {
            return redirect()->route('management.subscription.plan')->with('error', 'No active subscription found.');
        }

        $data = $request->validate(['plan_id' => ['required', 'exists:subscription_plans,id']]);
        $plan = SubscriptionPlan::active()->where('is_trial', false)->find($data['plan_id']);
        if (! $plan || $plan->is($subscription->subscriptionPlan)) {
            return back()->with('error', 'Invalid plan selection.');
        }

        $oldPlanId = $subscription->subscription_plan_id;
        $subscription->update(['subscription_plan_id' => $plan->id]);

        Log::info('subscription.plan_changed', [
            'user_id' => $request->user()->id,
            'old_plan_id' => $oldPlanId,
            'new_plan_id' => $plan->id,
        ]);

        return back()->with('success', "Your plan has been changed to {$plan->name}. The new billing amount will apply on your next renewal.");
    }

    public function select(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->business?->hasActiveSubscription()) {
            return redirect()->route('management.dashboard')->with('warning', 'You already have an active subscription.');
        }

        $data = $request->validate(['plan_id' => ['required', 'exists:subscription_plans,id']]);
        $plan = SubscriptionPlan::active()->where('is_trial', false)->find($data['plan_id']);
        if (! $plan) {
            return back()->with('error', 'Invalid plan selection.');
        }

        $trial = $this->trialSettings->get();
        $user->update([
            'selected_plan_id' => $plan->id,
            'is_verified' => true,
            'trial_ends_at' => $trial['enabled'] ? now()->addDays($trial['days']) : null,
        ]);

        return $trial['enabled']
            ? redirect()->route('management.dashboard')->with('success', "Your {$trial['days']}-day free trial has started!")
            : redirect()->route('management.subscription.payment')->with('info', 'Please complete payment to activate your subscription.');
    }

    public function onboarding(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user?->is_verified) {
            return redirect()->route('management.auth.verify-otp');
        }

        $plans = SubscriptionPlan::active()->where('is_trial', false)->orderBy('sort_order')->get();
        $monthlyPlans = $plans->where('interval', 'monthly')->values();
        $yearlyPlans = $plans->where('interval', 'yearly')->values();
        $trial = $this->trialSettings->get();
        $yearlySavingsPercent = $this->yearlySavings($monthlyPlans, $yearlyPlans);

        return view('auth.business.plans', compact(
            'user', 'plans', 'monthlyPlans', 'yearlyPlans', 'yearlySavingsPercent', 'trial'
        ));
    }

    public function checkout(SubscriptionPlan $plan): RedirectResponse
    {
        return redirect()->route('management.subscription.plan')
            ->with('warning', 'Please select a plan to start your free trial.');
    }

    private function yearlySavings($monthlyPlans, $yearlyPlans): ?int
    {
        if ($monthlyPlans->isEmpty() || $yearlyPlans->isEmpty()) {
            return null;
        }

        $annualMonthlyCost = (float) $monthlyPlans->min('amount') * 12;

        return $annualMonthlyCost > 0
            ? (int) round((1 - ((float) $yearlyPlans->min('amount') / $annualMonthlyCost)) * 100)
            : null;
    }
}
