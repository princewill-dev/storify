<?php

namespace App\Http\Controllers\Checkout;

use App\Http\Controllers\Controller;
use App\Mail\NewOrderAdminMail;
use App\Mail\OrderReceivedMail;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Mail\VendorOrderNotificationMail;
use App\Enums\PaymentStatus;
use App\Services\PaystackService;

class CheckoutController extends Controller
{
    public function index(Request $request, $store_slug)
    {
        // Ensure customer is authenticated
        if (!auth()->guard('customer')->check()) {
            // Store checkout context for post-login redirect
            session(['checkout_redirect' => true, 'checkout_store_slug' => $store_slug]);
            
            return redirect()->route('account.login')
                ->with('error', 'Please login to continue');
        }

        $customer = auth()->guard('customer')->user();

        // Get store by slug
        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();

        // Get cart from database
        $cart = $this->resolveCart($request, $store);
        
        // Log cart resolution for debugging
        Log::info('checkout_cart_resolution', [
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'cart_found' => $cart ? true : false,
            'cart_items_count' => $cart ? $cart->items->count() : 0,
            'guest_token' => $request->cookie('guest_token'),
        ]);
        
        if (!$cart || $cart->items->isEmpty()) {
            Log::warning('checkout_empty_cart_redirect', [
                'customer_id' => auth()->guard('customer')->id(),
                'store_slug' => $store_slug,
            ]);
            
            return redirect()->route('home.store.products.index', ['store_slug' => $store_slug])
                ->with('error', 'Your cart is empty. Please add items to cart before checkout.');
        }

        // Load cart items with product details
        $cart->load(['items.product.images']);

        // Hydrate summary items for the view to avoid heavy Blade logic
        $cartSummaryItems = $cart->items->map(function ($item) {
            $product = $item->product;
            $primaryImage = $product ? $product->primaryImage() : null;

            $unitAmountKobo = (int) ($item->unit_amount ?? 0);
            if ($unitAmountKobo <= 0 && $item->qty > 0 && $item->line_subtotal) {
                $unitAmountKobo = (int) round($item->line_subtotal / $item->qty);
            }
            if ($unitAmountKobo <= 0 && $product && $product->amount) {
                $unitAmountKobo = (int) round($product->amount * 100);
            }

            $lineSubtotalKobo = (int) ($item->line_subtotal ?? ($unitAmountKobo * $item->qty));

            return [
                'id' => $item->id,
                'name' => $item->name ?? optional($product)->name ?? 'Item',
                'qty' => $item->qty,
                'unit_amount' => $unitAmountKobo,
                'unit_price' => $unitAmountKobo / 100,
                'total' => $lineSubtotalKobo / 100,
                'image_path' => $primaryImage && $primaryImage->path ? $primaryImage->path : null,
                'has_product' => (bool) $product,
                'unit_hint' => optional($item->meta)['unit_hint'] ?? null,
            ];
        });

        // Get payment methods
        $paymentMethods = PaymentMethod::active()->get();

        // Get delivery routes
        $routes = DeliveryRoute::query()
            ->where('active', true)
            ->orderBy('state')
            ->orderBy('area')
            ->get(['id','state','area','fee','delivery_days']);

        $states = $routes->pluck('state')->unique()->values()->all();
        $areasByState = $routes->groupBy('state')->map(function($items){
            return $items->map(function($r){
                return [
                    'id' => $r->id,
                    'area' => $r->area,
                    'fee' => (int) $r->fee,
                    'days' => $r->delivery_days,
                ];
            })->values()->all();
        })->toArray();

        // VAT percentage
        $vatPercentage = optional(Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first())->percentage
            ?? optional(Vat::current())->percentage
            ?? 0;

        $customerAddresses = $customer->deliveryAddresses()
            ->with('deliveryRoute')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        $defaultAddressId = optional($customerAddresses->firstWhere('is_default', true))->id;

        $prefillAddress = [];
        if ($customerAddresses->isNotEmpty()) {
            $firstAddress = $customerAddresses->first();
            $route = $firstAddress->deliveryRoute;

            $prefillAddress = [
                'label' => $firstAddress->label,
                'recipient_name' => $firstAddress->recipient_name,
                'recipient_phone' => $firstAddress->recipient_phone,
                'company_name' => $firstAddress->company_name,
                'street_address' => $firstAddress->street_address,
                'apartment' => $firstAddress->apartment,
                'zip_code' => $firstAddress->zip_code,
                'map_link' => $firstAddress->map_link,
                'delivery_route_id' => optional($route)->id ?? $firstAddress->delivery_route_id,
                'delivery_state' => optional($route)->state,
                'delivery_area' => optional($route)->area,
                'delivery_fee' => optional($route)->fee,
                'delivery_days' => optional($route)->delivery_days,
                'is_default' => (bool) $firstAddress->is_default,
            ];
        }

        $addressDataset = $customerAddresses->mapWithKeys(function ($address) {
            $route = $address->deliveryRoute;

            return [
                $address->id => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'recipient_name' => $address->recipient_name,
                    'recipient_phone' => $address->recipient_phone,
                    'company_name' => $address->company_name,
                    'street_address' => $address->street_address,
                    'apartment' => $address->apartment,
                    'zip_code' => $address->zip_code,
                    'map_link' => $address->map_link,
                    'delivery_route_id' => optional($route)->id ?? $address->delivery_route_id,
                    'delivery_state' => optional($route)->state,
                    'delivery_area' => optional($route)->area,
                    'delivery_fee' => optional($route)->fee,
                    'delivery_days' => optional($route)->delivery_days,
                    'is_default' => (bool) $address->is_default,
                ],
            ];
        })->toArray();

        $oldSelectedAddress = $request->old('selected_address_id');
        if (is_null($oldSelectedAddress)) {
            if ($defaultAddressId) {
                $oldSelectedAddress = (string) $defaultAddressId;
            } elseif ($customerAddresses->isNotEmpty()) {
                $oldSelectedAddress = (string) $customerAddresses->first()->id;
            } else {
                $oldSelectedAddress = 'new';
            }
        }
        $hasOldInput = !is_null($oldSelectedAddress)
            || !is_null($request->old('delivery_route_id'))
            || !is_null($request->old('recipient_name'));

        $newAddressDefaults = [
            'label' => '',
            'recipient_name' => trim(sprintf('%s %s', $customer->first_name ?? '', $customer->last_name ?? '')),
            'recipient_phone' => $customer->phone ?? '',
            'company_name' => '',
            'street_address' => '',
            'apartment' => '',
            'zip_code' => '',
            'map_link' => '',
            'delivery_route_id' => null,
            'delivery_state' => null,
            'delivery_area' => null,
            'delivery_fee' => null,
            'delivery_days' => null,
            'is_default' => false,
        ];

        // Get Live First status and application
        $liveFirstStatus = $customer->live_first_status ?? \App\Enums\LiveFirstStatus::NOT_ENROLLED;
        $liveFirstApplication = $customer->liveFirstApplication;
        $canUseLiveFirst = in_array($liveFirstStatus->value, ['verified', 'testing', 'tested', 'approved']);

        return view('home.pages.checkout.checkout', [
            'store' => $store,
            'cart' => $cart,
            'cartSummaryItems' => $cartSummaryItems,
            'paymentMethods' => $paymentMethods,
            'states' => $states,
            'areasByState' => $areasByState,
            'vatPercentage' => $vatPercentage,
            'customer' => $customer,
            'customerAddresses' => $customerAddresses,
            'prefillAddress' => $prefillAddress,
            'addressDataset' => $addressDataset,
            'oldSelectedAddress' => $oldSelectedAddress,
            'hasOldInput' => $hasOldInput,
            'newAddressDefaults' => $newAddressDefaults,
            'defaultAddressId' => $defaultAddressId,
            'liveFirstStatus' => $liveFirstStatus,
            'liveFirstApplication' => $liveFirstApplication,
            'canUseLiveFirst' => $canUseLiveFirst,
        ]);
    }

    public function saveAddress(Request $request, $store_slug)
    {
        $customer = auth()->guard('customer')->user();
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Log incoming data for debugging
        Log::info('checkout.save_address.attempt', [
            'customer_id' => $customer->id,
            'data' => $request->all(),
        ]);

        try {
            $validated = $request->validate([
                'label' => 'nullable|string|max:255',
                'recipient_name' => 'required|string|max:255',
                'recipient_phone' => 'required|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'street_address' => 'required|string',
                'apartment' => 'nullable|string|max:255',
                'zip_code' => 'nullable|string|max:20',
                'map_link' => 'nullable|url|max:500',
                'delivery_route_id' => 'required|exists:delivery_routes,id',
                'set_default' => 'nullable|boolean',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('checkout.save_address.validation_failed', [
                'customer_id' => $customer->id,
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {

            $deliveryRoute = DeliveryRoute::findOrFail($validated['delivery_route_id']);

            $deliveryAddress = $customer->deliveryAddresses()->create([
                'label' => $validated['label'] ?? 'Home',
                'recipient_name' => $validated['recipient_name'],
                'recipient_phone' => $validated['recipient_phone'],
                'company_name' => $validated['company_name'] ?? null,
                'street_address' => $validated['street_address'],
                'apartment' => $validated['apartment'] ?? null,
                'zip_code' => $validated['zip_code'] ?? null,
                'map_link' => $validated['map_link'] ?? null,
                'delivery_route_id' => $deliveryRoute->id,
                'is_default' => $request->boolean('set_default'),
            ]);

            if ($request->boolean('set_default')) {
                $customer->deliveryAddresses()
                    ->where('id', '!=', $deliveryAddress->id)
                    ->update(['is_default' => false]);
            }

            Log::info('checkout.address_saved', [
                'customer_id' => $customer->id,
                'address_id' => $deliveryAddress->id,
                'is_default' => $deliveryAddress->is_default,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Address saved successfully',
                'address' => $deliveryAddress->load('deliveryRoute'),
            ]);

        } catch (\Exception $e) {
            Log::error('checkout.address_save_failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save address. Please try again.',
            ], 500);
        }
    }

    private function resolveCart(Request $request, Store $store): ?Cart
    {
        $userId = auth()->guard('customer')->id();
        $token = $request->cookie('guest_token');
        
        Log::info('resolve_cart_attempt', [
            'user_id' => $userId,
            'guest_token' => $token,
            'store_id' => $store->id,
        ]);
        
        $query = Cart::query()
            ->where('store_id', $store->id)
            ->where('status', 'active');
        
        if ($userId) {
            // First, try to find user's cart
            $userCart = Cart::where('store_id', $store->id)
                ->where('status', 'active')
                ->where('user_id', $userId)
                ->first();
            
            // If no user cart but we have a guest token, try to transfer guest cart
            if (!$userCart && $token) {
                $guestCart = Cart::where('store_id', $store->id)
                    ->where('status', 'active')
                    ->where('guest_token', $token)
                    ->whereNull('user_id')
                    ->first();
                
                if ($guestCart) {
                    // Transfer guest cart to user
                    $guestCart->user_id = $userId;
                    $guestCart->save();
                    
                    Log::info('guest_cart_transferred', [
                        'cart_id' => $guestCart->id,
                        'user_id' => $userId,
                        'guest_token' => $token,
                    ]);
                    
                    return $guestCart;
                }
            }
            
            return $userCart;
        } else if ($token) {
            return $query->where('guest_token', $token)->first();
        }
        
        return null;
    }

    public function process(Request $request, $store_slug)
    {
        // Ensure customer is authenticated
        $customer = auth()->guard('customer')->user();
        if (!$customer) {
            return redirect()->route('account.login')->with('error', 'Please login to continue');
        }

        Log::info('checkout_process_attempt', [
            'customer_id' => $customer->id,
            'store_slug' => $store_slug,
            'request_data' => $request->except(['_token'])
        ]);

        try {
            $validated = $request->validate([
                'label' => 'nullable|string|max:255',
                'recipient_name' => 'required|string|max:255',
                'recipient_phone' => 'required|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'street_address' => 'required|string',
                'apartment' => 'nullable|string|max:255',
                'zip_code' => 'nullable|string|max:20',
                'map_link' => 'nullable|url|max:500',
                'delivery_route_id' => 'nullable|exists:delivery_routes,id',
                'selected_address_id' => 'nullable',
                'notes' => 'nullable|string',
                'make_default' => 'nullable|boolean',
            ]);

            $selectedAddressId = $request->input('selected_address_id');
            $useExistingAddress = $selectedAddressId && $selectedAddressId !== 'new';

            $makeDefault = $request->boolean('make_default');

            if (!$useExistingAddress && empty($validated['delivery_route_id'])) {
                throw ValidationException::withMessages([
                    'delivery_route_id' => 'Please select your delivery location.',
                ]);
            }

            // Get store by slug
            $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();

            // Get cart from database
            $cart = $this->resolveCart($request, $store);

            if (!$cart || $cart->items->isEmpty()) {
                return back()->with('error', 'Your cart is empty')->withInput();
            }

            // Load cart items with product details
            $cart->load('items.product');

            $cartSource = data_get($cart->meta, 'source', 'checkout');

            $vatPercentage = optional(Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first())->percentage
                ?? optional(Vat::current())->percentage
                ?? 0;

            // Start database transaction
            DB::beginTransaction();

            $deliveryRoute = null;
            $deliveryAddress = null;
            $shippingFeeRaw = 0;
            $deliveryState = null;
            $deliveryArea = null;
            $deliveryDays = null;
            $resolvedRoute = null;

            if ($useExistingAddress) {
                $deliveryAddress = $customer->deliveryAddresses()
                    ->with('deliveryRoute')
                    ->findOrFail($selectedAddressId);

                $deliveryRouteId = $validated['delivery_route_id'] ?? $deliveryAddress->delivery_route_id;
                if ($deliveryRouteId) {
                    $deliveryRoute = DeliveryRoute::find($deliveryRouteId);
                }

                $resolvedRoute = $deliveryRoute ?? $deliveryAddress->deliveryRoute;
                if (!$resolvedRoute) {
                    throw ValidationException::withMessages([
                        'delivery_route_id' => 'Please select a valid delivery route for this address.',
                    ]);
                }

                $deliveryAddress->fill([
                    'label' => $validated['label'] ?: ($deliveryAddress->label ?? 'Delivery Address'),
                    'recipient_name' => $validated['recipient_name'],
                    'recipient_phone' => $validated['recipient_phone'],
                    'company_name' => $validated['company_name'] ?? null,
                    'street_address' => $validated['street_address'],
                    'apartment' => $validated['apartment'] ?? null,
                    'zip_code' => $validated['zip_code'] ?? null,
                    'map_link' => $validated['map_link'] ?? null,
                ]);

                if ($deliveryRoute) {
                    $deliveryAddress->delivery_route_id = $deliveryRoute->id;
                }

                $deliveryAddress->save();

                $shippingFeeRaw = (int) ($resolvedRoute->fee ?? 0);
                $deliveryState = $resolvedRoute->state;
                $deliveryArea = $resolvedRoute->area;
                $deliveryDays = $resolvedRoute->delivery_days;

                if ($makeDefault) {
                    $deliveryAddress->setAsDefault();
                }
            } else {
                $deliveryRoute = DeliveryRoute::findOrFail($validated['delivery_route_id']);

                $deliveryAddress = $customer->deliveryAddresses()->create([
                    'label' => $validated['label'] ?: 'Delivery Address',
                    'recipient_name' => $validated['recipient_name'],
                    'recipient_phone' => $validated['recipient_phone'],
                    'company_name' => $validated['company_name'] ?? null,
                    'street_address' => $validated['street_address'],
                    'apartment' => $validated['apartment'] ?? null,
                    'zip_code' => $validated['zip_code'] ?? null,
                    'map_link' => $validated['map_link'] ?? null,
                    'delivery_route_id' => $deliveryRoute->id,
                    'is_default' => false,
                ]);

                $shippingFeeRaw = (int) $deliveryRoute->fee;
                $deliveryState = $deliveryRoute->state;
                $deliveryArea = $deliveryRoute->area;
                $deliveryDays = $deliveryRoute->delivery_days;
                $resolvedRoute = $deliveryRoute;

                if ($makeDefault) {
                    $deliveryAddress->setAsDefault();
                }
            }

            // Calculate totals from cart
            $subtotalKobo = 0;
            $orderItems = [];

            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;
                $unitAmountKobo = (int) ($cartItem->unit_amount ?? 0);
                if ($unitAmountKobo <= 0 && $cartItem->qty > 0 && $cartItem->line_subtotal) {
                    $unitAmountKobo = (int) round($cartItem->line_subtotal / $cartItem->qty);
                }
                if ($unitAmountKobo <= 0 && $product && $product->amount) {
                    $unitAmountKobo = (int) round($product->amount * 100);
                }

                $lineSubtotalKobo = (int) ($cartItem->line_subtotal ?? ($unitAmountKobo * $cartItem->qty));
                $subtotalKobo += $lineSubtotalKobo;

                $unitPrice = round($unitAmountKobo / 100, 2);
                $itemSubtotal = round($lineSubtotalKobo / 100, 2);

                $orderItems[] = [
                    'product_id' => optional($product)->id
                        ?? $cartItem->product_id
                        ?? data_get($cartItem->meta, 'product_id'),
                    'product_name' => $cartItem->name
                        ?? optional($product)->name
                        ?? data_get($cartItem->meta, 'name')
                        ?? 'Item',
                    'product_code' => optional($product)->product_code
                        ?? data_get($cartItem->meta, 'product_code'),
                    'unit_price' => $unitPrice,
                    'quantity' => $cartItem->qty,
                    'subtotal' => $itemSubtotal,
                ];
            }

            $subtotal = round($subtotalKobo / 100, 2);
            $shippingFee = $shippingFeeRaw / 100;

            $cartTaxKobo = (int) ($cart->tax_total ?? 0);
            if ($cartTaxKobo <= 0 && $vatPercentage > 0) {
                $cartTaxKobo = (int) round($subtotalKobo * ($vatPercentage / 100));
            }
            $taxAmount = round($cartTaxKobo / 100, 2);
            $total = round($subtotal + $shippingFee + $taxAmount, 2);

            $order = Order::create([
                'store_id' => $store->id,
                'vendor_id' => $store->vendor_id,
                'customer_id' => $customer->id,
                'cart_id' => $cart->id,
                'source' => $cartSource,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $taxAmount,
                'total' => $total,
                'status' => \App\Enums\OrderStatus::PENDING->value,
                'delivery_route_id' => $resolvedRoute?->id,
                'delivery_state' => $deliveryState,
                'delivery_area' => $deliveryArea,
                'delivery_days' => $deliveryDays,
                'payment_method_id' => null,
                'notes' => $validated['notes'] ?? null,
                'delivery_address_id' => $deliveryAddress->id,
            ]);

            foreach ($orderItems as $itemData) {
                OrderItem::create(array_merge(['order_id' => $order->id], $itemData));
            }

            DB::commit();

            // Clear cart - delete cart items and mark cart as completed
            $cart->items()->delete();
            $cart->status = 'completed';
            $cart->save();

            Log::info('checkout_completed', [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'cart_id' => $cart->id
            ]);

            // Send email notifications
            try {
                // Send order confirmation to customer
                Mail::to($customer->email)->send(new OrderReceivedMail($order));
                
                // Send new order notification to admin
                $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL', 'admin@example.com'));
                if ($adminEmail && $adminEmail !== 'admin@example.com') {
                    Mail::to($adminEmail)->send(new NewOrderAdminMail($order));
                }

                $vendorEmail = $order->vendor?->email;
                if ($vendorEmail) {
                    Mail::to($vendorEmail)->send(new VendorOrderNotificationMail($order));
                }
                
                Log::info('checkout_emails_sent', [
                    'order_id' => $order->id,
                    'customer_email' => $customer->email,
                    'admin_email' => $adminEmail,
                    'vendor_email' => $vendorEmail,
                ]);
            } catch (\Exception $e) {
                Log::error('checkout_email_failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the checkout if email fails
            }

            // Redirect directly to payment method selection
            return redirect()->route('checkout.payment-methods', [
                'store_slug' => $store_slug,
                'order' => $order->order_number
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            Log::warning('checkout_validation_failed', ['errors' => $e->errors()]);
            return back()->withErrors($e->errors())->withInput();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('checkout_failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Checkout failed: ' . $e->getMessage())->withInput();
        }
    }

    public function showPaymentMethods($store_slug, Order $order)
    {
        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        $order->load(['customer', 'items.product', 'transactions']);
        $paymentMethods = PaymentMethod::active()->get();

        // Calculate payment amount
        // For Live First orders, show the transaction amount (10% down payment)
        // For regular orders, show the full order total
        $paymentAmount = $order->total;
        if ($order->source === 'live_first' && $order->transactions->isNotEmpty()) {
            $paymentAmount = $order->transactions->first()->amount;
        }

        return view('home.pages.checkout.select-payment-method', compact('store', 'order', 'paymentMethods', 'paymentAmount'));
    }

    public function selectPaymentMethod(Request $request, $store_slug, Order $order)
    {
        $validated = $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
        ]);

        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        $paymentMethodId = $validated['payment_method_id'];
        $paymentMethod = PaymentMethod::findOrFail($paymentMethodId);

        $order->update([
            'payment_method_id' => $paymentMethodId,
        ]);

        Log::info('payment_method_selected', [
            'order_id' => $order->id,
            'payment_method_id' => $paymentMethodId,
            'payment_method_code' => $paymentMethod->code,
        ]);

        // If Paystack is selected, initialize payment immediately
        if ($paymentMethod->code === 'paystack') {
            return $this->initializePaystackPayment($request, $order, $store);
        }

        // If Bank Transfer is selected, create transaction and redirect to bank transfer page
        if ($paymentMethod->code === 'bank_transfer') {
            $transaction = $order->transactions()->first();
            if ($transaction) {
                $transaction->update([
                    'payment_method_id' => $paymentMethodId,
                ]);
            } else {
                $transaction = Transaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethodId,
                    'amount' => $order->total,
                    'status' => 'pending',
                ]);
            }

            return redirect()->route('payment.bank-transfer', [
                'store_slug' => $store_slug,
                'order' => $order
            ]);
        }

        // For other payment methods, create/update transaction and redirect to payment page
        $transaction = $order->transactions()->first();
        if ($transaction) {
            $transaction->update([
                'payment_method_id' => $paymentMethodId,
            ]);
        } else {
            $transaction = Transaction::create([
                'order_id' => $order->id,
                'payment_method_id' => $paymentMethodId,
                'amount' => $order->total,
                'status' => 'pending',
            ]);
        }

        return redirect()->route('checkout.payment', [
            'store_slug' => $store_slug,
            'order' => $order->order_number
        ]);
    }

    /**
     * Initialize Paystack payment and redirect to authorization URL
     */
    protected function initializePaystackPayment(Request $request, Order $order, Store $store)
    {
        $customer = auth()->guard('customer')->user();
        
        if (!$customer) {
            return redirect()->back()->with('error', 'Please login to continue');
        }

        // Validate customer email
        if (!filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            Log::error('paystack.invalid_email', [
                'customer_id' => $customer->id,
                'email' => $customer->email,
            ]);
            
            return redirect()->back()->with('error', 'Invalid email address. Please update your profile with a valid email.');
        }

        try {
            $paystack = app(PaystackService::class);

            // Generate unique reference
            $reference = $paystack->generateReference('ORD');

            // Initialize payment
            $result = $paystack->initializePayment([
                'email' => $customer->email,
                'amount' => (int) ($order->total * 100), // Convert to kobo (integer)
                'currency' => 'NGN',
                'reference' => $reference,
                'callback_url' => route('payment.paystack.callback'),
                'metadata' => [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'customer_name' => $customer->name,
                    'store_slug' => $store->slug,
                    'custom_fields' => [
                        [
                            'display_name' => 'Order Code',
                            'variable_name' => 'order_code',
                            'value' => $order->order_code,
                        ],
                        [
                            'display_name' => 'Store',
                            'variable_name' => 'store_name',
                            'value' => $store->name,
                        ],
                    ],
                ],
            ]);

            if (!$result['success']) {
                Log::warning('paystack.initialize_failed', [
                    'order_id' => $order->id,
                    'message' => $result['message'],
                ]);

                return redirect()->back()->with('error', $result['message']);
            }

            // Create pending transaction
            $paymentMethod = PaymentMethod::where('code', 'paystack')->first();

            $transaction = $order->transactions()->first();
            if ($transaction) {
                $transaction->update([
                    'payment_method_id' => $paymentMethod->id,
                    'reference' => $reference,
                    'amount' => $order->total,
                    'currency' => 'NGN',
                    'status' => 'pending',
                    'metadata' => [
                        'authorization_url' => $result['data']['authorization_url'],
                        'access_code' => $result['data']['access_code'],
                    ],
                ]);
            } else {
                $transaction = Transaction::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'reference' => $reference,
                    'amount' => $order->total,
                    'currency' => 'NGN',
                    'status' => 'pending',
                    'metadata' => [
                        'authorization_url' => $result['data']['authorization_url'],
                        'access_code' => $result['data']['access_code'],
                    ],
                ]);
            }

            Log::info('paystack.transaction_created', [
                'transaction_id' => $transaction->id,
                'reference' => $reference,
                'order_id' => $order->id,
            ]);

            // Redirect to Paystack payment page
            return redirect($result['data']['authorization_url']);

        } catch (\Throwable $e) {
            Log::error('paystack.initialize.error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Failed to initialize payment. Please try again.');
        }
    }

    public function confirmPayment(Request $request, $store_slug, Order $order)
    {
        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        // Mark order as paid
        $order->update([
            'payment_status' => PaymentStatus::PAID,
        ]);

        // Update transaction status
        $transaction = $order->transactions()->first();
        if ($transaction) {
            $transaction->update([
                'status' => 'completed',
                'gateway_response' => json_encode(['manual_confirmation' => true]),
            ]);
        }

        Log::info('payment_confirmed', [
            'order_id' => $order->id,
            'payment_status' => PaymentStatus::PAID->value
        ]);

        // Clear pending order from session
        session()->forget('pending_order_id');

        return redirect()->route('checkout.payment', [
            'store_slug' => $store_slug,
            'order' => $order->order_number
        ])->with('success', 'Payment confirmed! Your order is being processed.');
    }

    public function payment($store_slug, Order $order)
    {
        // Verify order belongs to this store
        $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        $order->load(['customer', 'items.product', 'transactions.paymentMethod', 'deliveryRoute']);

        // Calculate payment amount
        // For Live First orders, show the transaction amount (10% down payment)
        // For regular orders, show the full order total
        $paymentAmount = $order->total;
        if ($order->source === 'live_first' && $order->transactions->isNotEmpty()) {
            $paymentAmount = $order->transactions->first()->amount;
        }

        return view('home.pages.checkout.payment', compact('store', 'order', 'paymentAmount'));
    }

    public function processLiveFirst(Request $request, $store_slug)
    {
        // Ensure customer is authenticated
        $customer = auth()->guard('customer')->user();
        if (!$customer) {
            return redirect()->route('account.login')->with('error', 'Please login to continue');
        }

        // Check if customer is eligible for Live First
        $liveFirstStatus = $customer->live_first_status ?? \App\Enums\LiveFirstStatus::NOT_ENROLLED;
        if (!in_array($liveFirstStatus->value, ['verified', 'testing', 'tested', 'approved'])) {
            return back()->with('error', 'You are not eligible for Live First program. Please complete KYC first.');
        }

        Log::info('live_first_checkout_attempt', [
            'customer_id' => $customer->id,
            'store_slug' => $store_slug,
            'live_first_status' => $liveFirstStatus->value,
        ]);

        try {
            $validated = $request->validate([
                'label' => 'nullable|string|max:255',
                'recipient_name' => 'required|string|max:255',
                'recipient_phone' => 'required|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'street_address' => 'required|string',
                'apartment' => 'nullable|string|max:255',
                'zip_code' => 'nullable|string|max:20',
                'map_link' => 'nullable|url|max:500',
                'delivery_route_id' => 'nullable|exists:delivery_routes,id',
                'selected_address_id' => 'nullable',
                'note' => 'nullable|string',
                'save_address' => 'nullable|boolean',
                'set_default' => 'nullable|boolean',
            ]);

            $selectedAddressId = $request->input('selected_address_id');
            $useExistingAddress = $selectedAddressId && $selectedAddressId !== 'new';

            if (!$useExistingAddress && empty($validated['delivery_route_id'])) {
                throw ValidationException::withMessages([
                    'delivery_route_id' => 'Please select your delivery location.',
                ]);
            }

            // Get store by slug
            $store = Store::where('slug', $store_slug)->where('status', 'active')->firstOrFail();

            // Get cart from database
            $cart = $this->resolveCart($request, $store);

            if (!$cart || $cart->items->isEmpty()) {
                return back()->with('error', 'Your cart is empty')->withInput();
            }

            // Load cart items with product details
            $cart->load('items.product');

            $vatPercentage = optional(Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first())->percentage
                ?? optional(Vat::current())->percentage
                ?? 0;

            // Start database transaction
            DB::beginTransaction();

            $deliveryRoute = null;
            $deliveryAddress = null;
            $shippingFeeRaw = 0;
            $deliveryState = null;
            $deliveryArea = null;
            $deliveryDays = null;
            $resolvedRoute = null;

            // Handle address (same logic as regular checkout)
            if ($useExistingAddress) {
                $deliveryAddress = $customer->deliveryAddresses()
                    ->with('deliveryRoute')
                    ->findOrFail($selectedAddressId);

                $deliveryRouteId = $validated['delivery_route_id'] ?? $deliveryAddress->delivery_route_id;
                if ($deliveryRouteId) {
                    $deliveryRoute = DeliveryRoute::find($deliveryRouteId);
                }

                $resolvedRoute = $deliveryRoute ?? $deliveryAddress->deliveryRoute;
                if (!$resolvedRoute) {
                    throw ValidationException::withMessages([
                        'delivery_route_id' => 'Please select a valid delivery route for this address.',
                    ]);
                }

                $deliveryAddress->update([
                    'delivery_route_id' => $resolvedRoute->id,
                ]);
            } else {
                // Create new address
                $deliveryRouteId = $validated['delivery_route_id'];
                $resolvedRoute = DeliveryRoute::findOrFail($deliveryRouteId);

                $deliveryAddress = $customer->deliveryAddresses()->create([
                    'label' => $validated['label'] ?? 'Home',
                    'recipient_name' => $validated['recipient_name'],
                    'recipient_phone' => $validated['recipient_phone'],
                    'company_name' => $validated['company_name'] ?? null,
                    'street_address' => $validated['street_address'],
                    'apartment' => $validated['apartment'] ?? null,
                    'zip_code' => $validated['zip_code'] ?? null,
                    'map_link' => $validated['map_link'] ?? null,
                    'delivery_route_id' => $resolvedRoute->id,
                    'is_default' => $request->boolean('set_default'),
                ]);

                if ($request->boolean('set_default')) {
                    $customer->deliveryAddresses()
                        ->where('id', '!=', $deliveryAddress->id)
                        ->update(['is_default' => false]);
                }
            }

            $shippingFeeRaw = (int) ($resolvedRoute->fee ?? 0);
            $deliveryState = $resolvedRoute->state;
            $deliveryArea = $resolvedRoute->area;
            $deliveryDays = $resolvedRoute->delivery_days;

            // Calculate order totals
            $subtotalKobo = $cart->items->sum('line_subtotal');
            $shippingFeeKobo = $shippingFeeRaw; // Already in kobo, don't multiply
            $subtotalWithShipping = $subtotalKobo + $shippingFeeKobo;
            $vatAmountKobo = (int) round($subtotalWithShipping * ($vatPercentage / 100));
            $totalKobo = $subtotalWithShipping + $vatAmountKobo;

            // Calculate 10% down payment for Live First
            $downPaymentKobo = (int) round($totalKobo * 0.10);
            $balanceKobo = $totalKobo - $downPaymentKobo;

            // Convert kobo to naira for database storage (matching regular checkout)
            $subtotal = round($subtotalKobo / 100, 2);
            $shippingFee = round($shippingFeeKobo / 100, 2);
            $vatAmount = round($vatAmountKobo / 100, 2);
            $total = round($totalKobo / 100, 2);
            $downPayment = round($downPaymentKobo / 100, 2);
            $balance = round($balanceKobo / 100, 2);
            $monthlyPayment = round($balance / 6, 2);

            Log::info('live_first_order_calculation', [
                'customer_id' => $customer->id,
                'total_kobo' => $totalKobo,
                'total_naira' => $total,
                'down_payment_kobo' => $downPaymentKobo,
                'down_payment_naira' => $downPayment,
                'balance_kobo' => $balanceKobo,
                'balance_naira' => $balance,
            ]);

            // Create order with Live First metadata
            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'delivery_address_id' => $deliveryAddress->id,
                'delivery_route_id' => $resolvedRoute->id,
                'delivery_state' => $deliveryState,
                'delivery_area' => $deliveryArea,
                'delivery_days' => $deliveryDays,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $vatAmount,
                'total' => $total,
                'status' => 'pending',
                'payment_status' => PaymentStatus::PENDING,
                'notes' => $validated['note'] ?? null,
                'source' => 'live_first',
                'meta' => [
                    'live_first' => true,
                    'down_payment' => $downPayment,
                    'balance' => $balance,
                    'payment_plan_months' => 6,
                    'monthly_payment' => $monthlyPayment,
                ],
            ]);

            // Create order items
            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;
                $unitAmountKobo = (int) ($cartItem->unit_amount ?? 0);
                $lineSubtotalKobo = (int) ($cartItem->line_subtotal ?? 0);
                
                $unitPrice = round($unitAmountKobo / 100, 2);
                $itemSubtotal = round($lineSubtotalKobo / 100, 2);
                
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => optional($product)->id ?? $cartItem->product_id,
                    'product_name' => optional($product)->name ?? $cartItem->name ?? 'Product',
                    'product_code' => optional($product)->product_code ?? null,
                    'quantity' => $cartItem->qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                ]);
            }

            // Create transaction for 10% down payment
            $transaction = Transaction::create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'amount' => $downPayment,
                'status' => 'pending',
                'payment_method_id' => null,
                'reference' => 'LF-' . strtoupper(uniqid()),
                'type' => 'order_payment',
                'description' => 'Live First Down Payment (10%)',
                'meta' => [
                    'live_first_down_payment' => true,
                    'full_order_total' => $total,
                    'balance_due' => $balance,
                ],
            ]);

            // Delete cart
            $cart->items()->delete();
            $cart->delete();

            DB::commit();

            Log::info('live_first_order_created', [
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_naira' => $total,
                'down_payment_naira' => $downPayment,
                'balance_naira' => $balance,
                'monthly_payment_naira' => $monthlyPayment,
            ]);

            // Redirect to payment methods page
            return redirect()->route('checkout.payment-methods', [
                'store_slug' => $store_slug,
                'order' => $order->order_number,
            ])->with('success', 'Live First order created! Please complete your 10% down payment.');

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('live_first_checkout_failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'An error occurred while processing your order. Please try again.')->withInput();
        }
    }
}
