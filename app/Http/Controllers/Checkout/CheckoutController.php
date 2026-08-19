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
    /** Get the correct route name for the current environment */
    private function routeName(string $name): string
    {
        return app()->environment('local') ? 'local.' . $name : $name;
    }

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
            'savedAddresses' => $customer ? $customer->deliveryAddresses()->with('deliveryRoute')->latest()->get() : collect(),
            'defaultAddress' => $customer?->defaultDeliveryAddress,
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
                        'business_id' => $store->business_id,
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

            // Pre-validate stock for ALL items before creating the order
            $stockErrors = [];
            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;
                if (!$product) continue;
                $qty = (int) $cartItem->qty;
                $stockLoc = \App\Models\StockLocation::where('locationable_type', \App\Models\Store::class)
                    ->where('locationable_id', $store->id)
                    ->where('product_id', $product->id)
                    ->first();
                $available = $stockLoc ? (int) $stockLoc->quantity : (int) $product->quantity;
                if ($available < $qty) {
                    $stockErrors[] = "{$product->name}: only {$available} available (requested {$qty})";
                }
            }
            if (!empty($stockErrors)) {
                return back()->with('error', 'Some items are out of stock: ' . implode('; ', $stockErrors))->withInput();
            }

            // Cart ownership check — verify cart belongs to this customer or guest
            if ($customer && $cart->user_id && $cart->user_type === Customer::class && $cart->user_id != $customer->id) {
                return back()->with('error', 'Invalid checkout session.')->withInput();
            }

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
                'business_id' => $store->business_id,
                'user_id' => $userId,
                'customer_id' => $customer->id,
                'source' => $cartSource,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'tax' => $taxAmount,
                'total' => $total,
                'status' => OrderStatus::PENDING->value,
                'delivery_state' => $deliveryState,
                'delivery_area' => $deliveryArea,
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
                        $ledger->recordRemoval($stockLoc, (int) $itemData['quantity'], $order, null, 'Storefront order — #' . $order->order_number);
                    } elseif ($product && $product->quantity >= (int) $itemData['quantity']) {
                        $product->decrement('quantity', (int) $itemData['quantity']);
                        $stockLoc = \App\Models\StockLocation::firstOrCreate([
                            'product_id' => $product->id,
                            'locationable_type' => \App\Models\Store::class,
                            'locationable_id' => $store->id,
                        ], ['quantity' => $product->quantity + (int) $itemData['quantity'], 'business_id' => $store->business_id]);
                        $ledger->recordRemoval($stockLoc, (int) $itemData['quantity'], $order, null, 'Storefront order — #' . $order->order_number);
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
                'business_id' => $order->business_id,
                'customer_id' => $customer->id,
                'cart_id' => $cart->id
            ]);

            // Emails are now sent after payment confirmation (see BankTransferController@confirmPayment)
            Log::info('checkout_completed_waiting_payment', [
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'customer_id' => $customer->id
            ]);

            // Redirect directly to payment method selection
            return redirect()->route($this->routeName('checkout.payment-methods'), [
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

        // Get store's assigned payment methods (RBAC-style: business → store → customer)
        $paymentMethods = $store->paymentMethods()->wherePivot('is_active', true)->get();
        $paymentAmount = $order->remainingBalance();

        return view('storefront.pages.select-payment-method', compact('store', 'order', 'paymentMethods', 'paymentAmount'));
    }

    public function selectPaymentMethod(Request $request, $store_subdomain, Order $order)
    {
        $store = Store::where('slug', $store_subdomain)->where('status', 'active')->firstOrFail();

        if ($order->store_id !== $store->id) {
            abort(404);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:paystack,bank_transfer',
            'amount' => 'nullable|numeric|min:1|max:' . $order->remainingBalance(),
        ]);

        $methodCode = $validated['payment_method'];
        $payAmount = $validated['amount'] ?? $order->remainingBalance();

        Log::info('payment_method_selected', [
            'order_id' => $order->id,
                'business_id' => $order->business_id,
            'payment_method' => $methodCode,
            'amount' => $payAmount,
        ]);

        // If Paystack is selected, initialize payment immediately
        if ($methodCode === 'paystack') {
            return $this->initializePaystackPayment($request, $order, $store, $payAmount);
        }

        // If Bank Transfer is selected, create a NEW transaction for the submitted amount
        if ($methodCode === 'bank_transfer') {
            Transaction::create([
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'payment_method_id' => null,
                'amount' => $payAmount,
                'status' => 'pending',
                'metadata' => ['is_partial' => $payAmount < $order->remainingBalance()],
            ]);

            return redirect()->route($this->routeName('payment.bank-transfer'), [
                'store_subdomain' => $store_subdomain,
                'order' => $order
            ]);
        }

        $transaction = $order->transactions()->first();
        if ($transaction) {
            $transaction->update([
                'payment_method_id' => $paymentMethodId,
            ]);
        } else {
            $transaction = Transaction::create([
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'payment_method_id' => $paymentMethodId,
                'amount' => $payAmount,
                'status' => 'pending',
            ]);
        }

        return redirect()->route($this->routeName('checkout.payment'), [
                'store_subdomain' => $store_subdomain,
            'order' => $order->order_number
        ]);
    }

    /**
     * Initialize Paystack payment and redirect to authorization URL
     */
    protected function initializePaystackPayment(Request $request, Order $order, Store $store, float $payAmount)
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
                'amount' => (int) round($payAmount * 100), // Convert to kobo (integer)
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
                'business_id' => $order->business_id,
                    'message' => $result['message'],
                ]);

                return redirect()->back()->with('error', $result['message']);
            }

            // Create a NEW pending transaction per attempt (supports split payments)
            $paymentMethod = PaymentMethod::where('code', 'paystack')->first();

            Transaction::create([
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'payment_method_id' => $paymentMethod->id,
                'reference' => $reference,
                'amount' => $payAmount,
                'currency' => 'NGN',
                'status' => 'pending',
                'metadata' => [
                    'authorization_url' => $result['data']['authorization_url'],
                    'access_code' => $result['data']['access_code'],
                    'is_partial' => $payAmount < $order->remainingBalance(),
                ],
            ]);

            Log::info('paystack.transaction_created', [
                'reference' => $reference,
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'amount' => $payAmount,
            ]);

            // Redirect to Paystack payment page
            return redirect($result['data']['authorization_url']);

        } catch (\Throwable $e) {
            Log::error('paystack.initialize.error', [
                'order_id' => $order->id,
                'business_id' => $order->business_id,
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
                'business_id' => $order->business_id,
            'payment_status' => PaymentStatus::PAID->value
        ]);

        // Clear pending order from session
        session()->forget('pending_order_id');

        return redirect()->route($this->routeName('checkout.payment'), [
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

        return view('home.pages.checkout.payment', compact('store', 'order'));
    }

}

