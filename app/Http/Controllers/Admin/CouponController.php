<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function index(): View
    {
        $coupons = Coupon::with('subscriptionPlan')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('admin.coupons.index', compact('coupons'));
    }

    public function create(): View
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->get(['id', 'name', 'interval']);

        return view('admin.coupons.form', ['coupon' => new Coupon, 'plans' => $plans]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateData($request);

        $validated['code'] = strtoupper(trim($validated['code']));

        $coupon = Coupon::create($validated);

        Log::info('coupon.created', [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'admin_id' => auth()->id(),
        ]);

        return redirect()->route('admin.coupons.index')
            ->with('success', "Coupon \"{$coupon->code}\" created successfully.");
    }

    public function edit(Coupon $coupon): View
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->get(['id', 'name', 'interval']);

        return view('admin.coupons.form', compact('coupon', 'plans'));
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $validated = $this->validateData($request, $coupon->id);

        $validated['code'] = strtoupper(trim($validated['code']));

        $coupon->update($validated);

        Log::info('coupon.updated', [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'admin_id' => auth()->id(),
        ]);

        return redirect()->route('admin.coupons.index')
            ->with('success', "Coupon \"{$coupon->code}\" updated successfully.");
    }

    public function toggleActive(Coupon $coupon): RedirectResponse
    {
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return redirect()->route('admin.coupons.index')
            ->with('success', "Coupon \"{$coupon->code}\" ".($coupon->is_active ? 'activated' : 'deactivated').'.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $code = $coupon->code;
        $coupon->delete();

        Log::info('coupon.deleted', [
            'code' => $code,
            'admin_id' => auth()->id(),
        ]);

        return redirect()->route('admin.coupons.index')
            ->with('success', "Coupon \"{$code}\" deleted.");
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => 'nullable|string|max:255',
            'code' => [
                'required', 'string', 'max:50',
                'unique:coupons,code'.($ignoreId ? ",{$ignoreId}" : ''),
            ],
            'subscription_plan_id' => 'nullable|exists:subscription_plans,id',
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0.01',
            'max_uses' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);
    }
}
