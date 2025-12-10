<?php

namespace App\Http\Controllers\Home;

use App\Enums\FamilyPackStatus;
use App\Enums\PaymentInterval;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FamilyPackCartController;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryInterval;
use App\Models\DeliveryRoute;
use App\Models\FamilyPackOrder;
use App\Models\FamilyPackItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class FamilyPackController extends Controller
{
    /**
     * Browse products for family pack
     */
    public function index($store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();

        $products = Product::where('store_id', $store->id)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->paginate(24);

        $cart = FamilyPackCartController::getCart();
        $cartItemCount = FamilyPackCartController::getItemCount();

        return view('home.pages.family-pack.index', compact('store', 'products', 'cart', 'cartItemCount'));
    }

    /**
     * Show checkout page
     */
    public function checkout($store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $cart = FamilyPackCartController::getCart();

        if (empty($cart) || empty($cart['items'])) {
            return redirect()->route('family-pack.index', ['store_slug' => $store->slug])->with('error', 'Your family pack cart is empty.');
        }

        if (!Auth::guard('customer')->check()) {
            // Preserve intended redirect for post-auth flow
            session([
                'family_pack_redirect' => true,
                'family_pack_store_slug' => $store->slug,
            ]);
            return redirect()->route('account.register', [
                'flow' => 'family_pack',
                'store_slug' => $store->slug,
            ])->with('info', 'Please register or login to continue with your family pack order.');
        }

        $customer = Auth::guard('customer')->user();
        $deliveryAddresses = DeliveryAddress::where('customer_id', $customer->id)->with('deliveryRoute')->get();
        $deliveryIntervals = DeliveryInterval::active()->get();
        $paymentIntervals = PaymentInterval::cases();
        $deliveryRoutes = DeliveryRoute::where('active', true)->get();
        $states = $deliveryRoutes->pluck('state')->unique()->values();

        $subtotal = FamilyPackCartController::getSubtotal();

        return view('home.pages.family-pack.checkout', compact(
            'store',
            'cart',
            'deliveryAddresses',
            'deliveryIntervals',
            'paymentIntervals',
            'deliveryRoutes',
            'states',
            'subtotal'
        ));
    }

    /**
     * Submit family pack order request
     */
    public function submitOrder(Request $request, $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $request->validate([
            'delivery_address_id' => 'required|exists:delivery_addresses,id',
            'delivery_route_id' => 'required|exists:delivery_routes,id',
            'pack_type' => 'required|in:single,recurring',
            // payment_interval will be inferred from delivery_interval
            'delivery_interval_id' => 'required_if:pack_type,recurring|exists:delivery_intervals,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (!Auth::guard('customer')->check()) {
            session([
                'family_pack_redirect' => true,
                'family_pack_store_slug' => $store->slug,
            ]);
            return redirect()->route('account.register', [
                'flow' => 'family_pack',
                'store_slug' => $store->slug,
            ]);
        }

        $customer = Auth::guard('customer')->user();
        $cart = FamilyPackCartController::getCart();

        if (empty($cart) || empty($cart['items'])) {
            return redirect()->route('family-pack.index', ['store_slug' => $store->slug])->with('error', 'Your cart is empty.');
        }

        try {
            DB::beginTransaction();

            // Calculate totals
            $subtotal = 0;
            foreach ($cart['items'] as $item) {
                if ($item['is_custom']) {
                    $subtotal += $item['budgeted_amount'];
                } else {
                    $subtotal += $item['unit_price'] * $item['quantity'];
                }
            }

            // Calculate shipping and tax
            $deliveryRoute = DeliveryRoute::find($request->delivery_route_id);
            $shippingFee = $deliveryRoute ? ($deliveryRoute->fee / 100) : 0;
            
            $vat = Vat::current();
            $vatPercentage = $vat ? $vat->percentage : 0;
            $tax = ($subtotal + $shippingFee) * ($vatPercentage / 100);
            
            $estimatedTotal = $subtotal + $shippingFee + $tax;

            // Determine payment interval from delivery interval (1:1) and total cycles for recurring
            $totalCycles = null;
            $paymentIntervalValue = null;
            if ($request->pack_type === 'recurring') {
                $deliveryInterval = DeliveryInterval::find($request->delivery_interval_id);
                $slug = $deliveryInterval?->slug ? strtolower($deliveryInterval->slug) : null;
                $days = $deliveryInterval?->days_count;

                $mapSlug = function (?string $s) {
                    if (!$s) return null;
                    return match($s) {
                        'weekly', 'week', '7_days', '7days' => 'weekly',
                        'monthly', 'month', '30_days', '30days' => 'monthly',
                        '6_months', '6-months', 'six_months', 'six-months' => '6_months',
                        '12_months', '12-months', 'twelve_months', 'twelve-months' => '12_months',
                        default => null,
                    };
                };

                $paymentIntervalValue = $mapSlug($slug);
                if (!$paymentIntervalValue && $days) {
                    if ($days == 7) $paymentIntervalValue = 'weekly';
                    elseif ($days >= 28 && $days <= 31) $paymentIntervalValue = 'monthly';
                }
                if (!$paymentIntervalValue) {
                    // Fallback to monthly if unknown
                    $paymentIntervalValue = 'monthly';
                }

                $paymentIntervalEnum = PaymentInterval::from($paymentIntervalValue);
                $totalCycles = $paymentIntervalEnum->cycles();
            }

            // Generate unique pack code
            $packCode = 'PACK-' . strtoupper(uniqid());

            // Create family pack order
            $familyPackOrder = FamilyPackOrder::create([
                'pack_code' => $packCode,
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'delivery_address_id' => $request->delivery_address_id,
                'delivery_route_id' => $request->delivery_route_id,
                'pack_type' => $request->pack_type,
                'payment_interval' => $request->pack_type === 'recurring' ? $paymentIntervalValue : null,
                'delivery_interval_id' => $request->delivery_interval_id,
                'total_cycles' => $totalCycles,
                'subtotal' => $subtotal,
                'estimated_total' => $estimatedTotal,
                'status' => FamilyPackStatus::PENDING_REVIEW,
                'notes' => $request->notes,
                'last_updated_by' => 'customer',
            ]);

            // Create family pack items
            foreach ($cart['items'] as $item) {
                FamilyPackItem::create([
                    'family_pack_order_id' => $familyPackOrder->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_code' => $item['product_code'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['is_custom'] ? null : $item['unit_price'],
                    'subtotal' => $item['is_custom'] ? 0 : ($item['unit_price'] * $item['quantity']),
                    'is_custom' => $item['is_custom'],
                    'budgeted_amount' => $item['is_custom'] ? $item['budgeted_amount'] : null,
                ]);
            }

            DB::commit();

            // Clear cart
            Session::forget('family_pack_cart');

            Log::info('Family pack order submitted', [
                'pack_code' => $packCode,
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'pack_type' => $request->pack_type,
            ]);

            // Send email notifications
            try {
                \Illuminate\Support\Facades\Mail::to($customer->email)->queue(new \App\Mail\FamilyPackRequestReceived($familyPackOrder));
                
                // Notify admin (assuming superadmin email or store owner)
                // For now, sending to a configured admin email or the store's email
                $adminEmail = config('mail.from.address'); // Or specific admin email
                if ($adminEmail) {
                    \Illuminate\Support\Facades\Mail::to($adminEmail)->queue(new \App\Mail\AdminNewFamilyPackRequest($familyPackOrder));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send family pack emails', ['error' => $e->getMessage()]);
            }

            return redirect()->route('family-pack.review', [
                'store_slug' => $store->slug,
                'packCode' => $familyPackOrder->pack_code,
            ])
                ->with('success', 'Your family pack request has been submitted! We will review and get back to you soon.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to submit family pack order', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to submit your request. Please try again.');
        }
    }

    /**
     * View family pack order details and track status
     */
    public function review($store_slug, $packCode)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        if (!Auth::guard('customer')->check()) {
            return redirect()->route('account.login');
        }

        $customer = Auth::guard('customer')->user();
        
        $familyPackOrder = FamilyPackOrder::where('pack_code', $packCode)
            ->where('customer_id', $customer->id)
            ->with(['items.product', 'store', 'deliveryAddress', 'deliveryRoute', 'deliveryInterval', 'reviewer'])
            ->firstOrFail();

        // Calculate totals
        $shippingFee = $familyPackOrder->deliveryRoute ? ($familyPackOrder->deliveryRoute->fee / 100) : 0;
        $vat = Vat::current();
        $vatPercentage = $vat ? $vat->percentage : 0;
        $tax = ($familyPackOrder->subtotal + $shippingFee) * ($vatPercentage / 100);

        return view('home.pages.family-pack.review', compact('familyPackOrder', 'shippingFee', 'tax', 'vatPercentage'));
    }

    /**
     * Customer accepts admin's pricing/updates
     */
    public function accept($store_slug, $packCode)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        if (!Auth::guard('customer')->check()) {
            return redirect()->route('customer.login');
        }

        $customer = Auth::guard('customer')->user();
        
        $familyPackOrder = FamilyPackOrder::where('pack_code', $packCode)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        if ($familyPackOrder->status !== FamilyPackStatus::APPROVED) {
            return back()->with('error', 'This order cannot be accepted at this  time.');
        }

        try {
            // For single purchase, admin will finalize
            // For recurring, admin will activate subscription
            // Customer acceptance just confirms they're ready for admin to proceed
            
            $familyPackOrder->update([
                'last_updated_by' => 'customer',
            ]);

            return back()->with('success', 'Thank you for accepting! The admin will finalize your order shortly.');

        } catch (\Exception $e) {
            Log::error('Failed to accept family pack order', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to accept order. Please try again.');
        }
    }
}
