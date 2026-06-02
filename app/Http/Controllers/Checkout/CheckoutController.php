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
use App\Models\User;
use App\Enums\OrderStatus;

class CheckoutController extends Controller
{
    public function index(Request $request, $store_subdomain, $token)
    {
        $customer = auth()->guard('customer')->user();

        // Get store by slug
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();

        // Get cart
        $cart = Cart::where('checkout_token', $token)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();
            
        if (!$cart) {
            return redirect()->route('home.store.cart', ['store_subdomain' => $store_subdomain])
            ->with('error', 'Invalid or expired checkout session.');
        }
        
        // Log cart resolution for debugging
        Log::info('checkout_cart_resolution', [
            'customer_id' => $customer?->id,
            'store_id' => $store->id,
            'cart_found' => $cart ? true : false,
            'cart_items_count' => $cart ? $cart->items->count() : 0,
            'guest_token' => $request->cookie('guest_token'),
            'checkout_token' => $token
        ]);
        
        if (!$cart || $cart->items->isEmpty()) {
            return redirect()->route('home.store.products.index', ['store_subdomain' => $store_subdomain])
                ->with('error', 'Your cart is empty. Please add items to cart before checkout.');
        }

        // Load cart items with product details
        $cart->load(['items.product.images', 'deliveryRoute']);

        // Hydrate summary items for the view
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

        // Check for delivery route selection (Persisted in Cart or via Query Param)
        $preselectedRoute = null;
        $shippingFee = 0;
        
        if ($cart->delivery_route_id) {
            $preselectedRoute = $cart->deliveryRoute; // Relationship loaded above
            if ($preselectedRoute && $preselectedRoute->active) {
                $shippingFee = $preselectedRoute->fee;
            }
        } elseif ($request->has('delivery_route_id')) {
            $preselectedRoute = DeliveryRoute::where('store_id', $store->id)
                ->where('id', $request->delivery_route_id)
                ->where('active', true)
                ->first();
            
            if ($preselectedRoute) {
                $shippingFee = $preselectedRoute->fee;
            }
        }

        // VAT percentage
        return view('storefront.pages.checkout', [
            'store' => $store,
            'cart' => $cart,
            'cartSummaryItems' => $cartSummaryItems,
            'paymentMethods' => $paymentMethods,
            'vatPercentage' => 0, // VAT removed from flow
            'customer' => $customer,
            'preselectedRoute' => $preselectedRoute,
            'shippingFee' => $shippingFee,
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
                'city' => 'required|string|max:255',
                'state' => 'required|string|max:255',
                'country' => 'required|string|max:255',
                'landmark' => 'nullable|string|max:255',
                'zip_code' => 'nullable|string|max:20',
                'map_link' => 'nullable|url|max:500',
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

            $deliveryAddress = $customer->deliveryAddresses()->create([
                'label' => $validated['label'] ?? 'Home',
                'recipient_name' => $validated['recipient_name'],
                'recipient_phone' => $validated['recipient_phone'],
                'company_name' => $validated['company_name'] ?? null,
                'street_address' => $validated['street_address'],
                'apartment' => $validated['apartment'] ?? null,
                'city' => $validated['city'],
                'state' => $validated['state'],
                'country' => $validated['country'],
                'landmark' => $validated['landmark'] ?? null,
                'zip_code' => $validated['zip_code'] ?? null,
                'map_link' => $validated['map_link'] ?? null,
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
                'address' => $deliveryAddress,
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

    public function process(Request $request, $store_subdomain)
    {
        $customer = auth()->guard('customer')->user();
        
        Log::info('checkout_process_attempt', [
            'customer_id' => $customer?->id,
            'store_slug' => $store_subdomain,
            'request_data' => $request->except(['_token'])
        ]);

        try {
            $rules = [
                'first_name' => 'required_if:is_guest,true|string|max:255',
                'last_name' => 'required_if:is_guest,true|string|max:255',
                'email' => 'required_if:is_guest,true|email|max:255',
                'phone' => 'required_if:is_guest,true|string|max:20',
                'street_address' => 'required|string',
                'apartment' => 'nullable|string|max:255',
                'country' => 'nullable|string|max:255',
                'state' => 'required|string|max:255',
                'city' => 'required|string|max:255',
                'landmark' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'delivery_route_id' => 'nullable|exists:delivery_routes,id',
                'checkout_token' => 'nullable|string',
            ];

            // If not logged in, these are required
            if (!$customer) {
                $rules['first_name'] = 'required|string|max:255';
                $rules['last_name'] = 'required|string|max:255';
                $rules['email'] = 'required|email|max:255';
                $rules['phone'] = 'required|string|max:20';
            }

            $validated = $request->validate($rules);

            // Get store by slug
            $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();

            // Resolve/Create Customer if guest
            if (!$customer) {
                $customer = Customer::where('email', $validated['email'])->first();
                if (!$customer) {
                    $customer = Customer::create([
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'email' => $validated['email'],
                        'phone' => $validated['phone'],
                        'ip_address' => $request->ip(),
                        'password' => bcrypt(str()->random(16)), // Dummy password for technical requirement
                    ]);
                }
            }

            // Get cart from database: prioritize checkout_token for isolated Buy Now carts
            $cart = null;
            if (!empty($validated['checkout_token'])) {
                $cart = Cart::where('checkout_token', $validated['checkout_token'])
                    ->where('store_id', $store->id)
                    ->where('status', 'active')
                    ->first();
            }
            if (!$cart) {
                $cart = $this->resolveCart($request, $store);
            }

            if (!$cart || $cart->items->isEmpty()) {
                return back()->with('error', 'Your cart is empty')->withInput();
            }

            // Load cart items with product details
            $cart->load('items.product');

            $cartSource = data_get($cart->meta, 'source', 'checkout');

            $vatPercentage = 0; // VAT removed from flow

            // Start database transaction
            DB::beginTransaction();

            $deliveryAddress = $customer->deliveryAddresses()->create([
                'recipient_name' => $customer->full_name,
                'recipient_phone' => $customer->phone,
                'street_address' => $validated['street_address'],
                'apartment' => $validated['apartment'] ?? null,
                'country' => $validated['country'] ?? 'Nigeria',
                'state' => $validated['state'],
                'city' => $validated['city'],
                'landmark' => $validated['landmark'] ?? null,
            ]);

            $shippingFeeRaw = 0;
            $deliveryState = $validated['state'];
            $deliveryArea = $validated['city'];
            $deliveryDays = null;
            $resolvedRoute = null;

            // Use persisted route from cart if available, otherwise fall back to request
            if ($cart->delivery_route_id) {
                $route = DeliveryRoute::find($cart->delivery_route_id);
                if ($route && $route->store_id == $store->id) {
                    $shippingFeeRaw = $route->fee;
                    $deliveryDays = $route->delivery_days;
                    
                    // Override inputs with route data to ensure consistency
                    // but we might want to keep user's typed address if it differs slightly?
                    // Generally, if they selected a route, the state/area should match.
                }
            } elseif ($request->filled('delivery_route_id')) {
                $route = DeliveryRoute::find($request->delivery_route_id);
                if ($route && $route->store_id == $store->id) {
                    $shippingFeeRaw = $route->fee;
                    $deliveryDays = $route->delivery_days;
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

            $cartTaxKobo = 0; // Tax removed from flow
            $taxAmount = round($cartTaxKobo / 100, 2);
            $total = round($subtotal + $shippingFee + $taxAmount, 2);

            // Verify vendor exists to avoid foreign key constraint violation
            $userId = null;
            if ($store->user_id) {
                $userExists = User::where('id', $store->user_id)->exists();
                $userId = $userExists ? $store->user_id : null;
            }

            $order = Order::create([
                'store_id' => $store->id,
                'user_id' => $userId,
                'customer_id' => $customer->id,
                'cart_id' => $cart->id,
                'source' => $cartSource,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $taxAmount,
                'total' => $total,
                'status' => OrderStatus::PENDING->value,
                'delivery_state' => $deliveryState,
                'delivery_area' => $deliveryArea,
                'payment_method_id' => null,
                'notes' => $validated['notes'] ?? null,
                'delivery_address_id' => $deliveryAddress->id,
            ]);

            foreach ($orderItems as $itemData) {
                OrderItem::create(array_merge(['order_id' => $order->id], $itemData));
            }

            $ledger = app(\App\Services\StockLedgerService::class);

            foreach ($orderItems as $itemData) {
                if (!empty($itemData['product_id']) && isset($itemData['quantity'])) {
                    $product = Product::find($itemData['product_id']);
                    $stockLoc = \App\Models\StockLocation::where('locationable_type', \App\Models\Store::class)
                        ->where('locationable_id', $store->id)
                        ->where('product_id', $product->id)
                        ->first();

                    if ($stockLoc && $stockLoc->quantity >= (int) $itemData['quantity']) {
                        $product->decrement('quantity', (int) $itemData['quantity']);
                        $ledger->recordRemoval($stockLoc, (int) $itemData['quantity'], $order, $customer, 'Storefront order — #' . $order->order_number);
                    } elseif ($product && $product->quantity >= (int) $itemData['quantity']) {
                        $product->decrement('quantity', (int) $itemData['quantity']);
                        $stockLoc = \App\Models\StockLocation::firstOrCreate([
                            'product_id' => $product->id,
                            'locationable_type' => \App\Models\Store::class,
                            'locationable_id' => $store->id,
                        ], ['quantity' => $product->quantity + (int) $itemData['quantity'], 'business_id' => $store->business_id]);
                        $ledger->recordRemoval($stockLoc, (int) $itemData['quantity'], $order, $customer, 'Storefront order — #' . $order->order_number);
                    }
                }
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

            // Emails are now sent after payment confirmation (see BankTransferController@confirmPayment)
            Log::info('checkout_completed_waiting_payment', [
                'order_id' => $order->id,
                'customer_id' => $customer->id
            ]);

            // Redirect directly to payment method selection
            return redirect()->route('checkout.payment-methods', [
                'store_subdomain' => $store_subdomain,
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

    public function showPaymentMethods($store_subdomain, Order $order)
    {
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
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

        return view('storefront.pages.select-payment-method', compact('store', 'order', 'paymentMethods', 'paymentAmount'));
    }

    public function selectPaymentMethod(Request $request, $store_subdomain, Order $order)
    {
        $validated = $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
        ]);

        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
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
                'store_subdomain' => $store_subdomain,
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
                'store_subdomain' => $store_subdomain,
            'order' => $order->order_number
        ]);
    }

    /**
     * Initialize Paystack payment and redirect to authorization URL
     */
    protected function initializePaystackPayment(Request $request, Order $order, Store $store)
    {
        $customer = $order->customer;
        
        if (!$customer) {
            return redirect()->back()->with('error', 'Customer information missing');
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
                    'order_number' => $order->order_number,
                    'customer_name' => $customer->name,
                    'store_slug' => $store->slug,
                    'custom_fields' => [
                        [
                            'display_name' => 'Order Number',
                            'variable_name' => 'order_number',
                            'value' => $order->order_number,
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

    public function confirmPayment(Request $request, $store_subdomain, Order $order)
    {
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
        if ($order->store_id !== $store->id) {
            abort(404);
        }

        // Mark order as paid
        // Order payment status is now derived from transaction status
        /*
        $order->update([
            'payment_status' => PaymentStatus::PAID,
        ]);
        */

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
                'store_subdomain' => $store_subdomain,
            'order' => $order->order_number
        ])->with('success', 'Payment confirmed! Your order is being processed.');
    }

    public function payment($store_subdomain, Order $order)
    {
        // Verify order belongs to this store
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();
        
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

            $ledger = app(\App\Services\StockLedgerService::class);

            // Decrement stock for ordered products
            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;
                $stockLoc = \App\Models\StockLocation::where('locationable_type', \App\Models\Store::class)
                    ->where('locationable_id', $store->id)
                    ->where('product_id', $product->id)
                    ->first();

                if ($stockLoc && $stockLoc->quantity >= $cartItem->qty) {
                    $product->decrement('quantity', $cartItem->qty);
                    $ledger->recordRemoval($stockLoc, $cartItem->qty, $order, $customer, 'LiveFirst order — #' . $order->order_number);
                } elseif ($product && $product->quantity >= $cartItem->qty) {
                    $product->decrement('quantity', $cartItem->qty);
                    $stockLoc = \App\Models\StockLocation::firstOrCreate([
                        'product_id' => $product->id,
                        'locationable_type' => \App\Models\Store::class,
                        'locationable_id' => $store->id,
                    ], ['quantity' => $product->quantity + $cartItem->qty, 'business_id' => $store->business_id]);
                    $ledger->recordRemoval($stockLoc, $cartItem->qty, $order, $customer, 'LiveFirst order — #' . $order->order_number);
                }
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
