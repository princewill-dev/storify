<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PaymentMethodController extends Controller
{
    public function index(): View
    {
        $methods = PaymentMethod::query()
            ->orderBy('name')
            ->get();

        return view('admin.payment_methods.index', compact('methods'));
    }

    public function toggle(PaymentMethod $paymentMethod): RedirectResponse
    {
        $paymentMethod->update([
            'is_active' => ! $paymentMethod->is_active,
        ]);

        $message = $paymentMethod->is_active
            ? 'enabled'
            : 'disabled';

        return redirect()->route('admin.payment-methods.index')
            ->with('success', $paymentMethod->name . ' ' . ucfirst($message) . ' successfully.');
    }
}
