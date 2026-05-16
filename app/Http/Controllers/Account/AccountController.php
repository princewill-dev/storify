<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtpMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class AccountController extends Controller
{
    /**
     * Show login form
     */
    public function showLogin(Request $request)
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('account.dashboard');
        }
        
        $flow = $request->query('flow');
        // Preserve family-pack redirect if coming from family pack checkout
        if ($flow === 'family_pack') {
            if (!session('family_pack_redirect')) {
                session([
                    'family_pack_redirect' => true,
                    'family_pack_store_slug' => $request->get('store_slug', session('family_pack_store_slug')),
                ]);
            }
        }
        // Preserve live-first redirect if coming from live first enrollment
        if ($flow === 'live-first') {
            if (!session('live_first_redirect')) {
                session([
                    'live_first_redirect' => true,
                    'live_first_store_slug' => $request->get('store_slug', session('live_first_store_slug')),
                ]);
            }
        }
        return view('account.login', ['flow' => $flow]);
    }

    /**
     * Handle login
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::guard('customer')->attempt($credentials, false)) {
            $request->session()->regenerate();
            
            $customer = Auth::guard('customer')->user();
            Log::info('customer.login.success', ['customer_id' => $customer->id, 'email' => $customer->email]);
            
            // Merge guest cart with customer cart
            $this->mergeGuestCart($request, $customer);
            
            // Check if coming from live first enrollment
            if (session('live_first_redirect')) {
                $storeSlug = session('live_first_store_slug', 'zimozi_swift');
                session()->forget(['live_first_redirect', 'live_first_store_slug']);
                
                Log::info('customer.login.live_first_redirect', [
                    'customer_id' => $customer->id,
                    'store_slug' => $storeSlug,
                ]);
                
                return redirect()->route('home.live-first.kyc', ['store_slug' => $storeSlug]);
            }
            
            // Check if coming from family pack checkout
            if (session('family_pack_redirect')) {
                $storeSlug = session('family_pack_store_slug');
                session()->forget(['family_pack_redirect', 'family_pack_store_slug']);
                
                Log::info('customer.login.family_pack_redirect', [
                    'customer_id' => $customer->id,
                    'store_slug' => $storeSlug,
                ]);
                
                return redirect()->route('family-pack.checkout', ['store_slug' => $storeSlug]);
            }

            // Check if coming from bulk buy checkout
            if (session('bulk_buy_redirect')) {
                $storeSlug = session('bulk_buy_store_slug');
                session()->forget(['bulk_buy_redirect', 'bulk_buy_store_slug']);
                
                Log::info('customer.login.bulk_buy_redirect', [
                    'customer_id' => $customer->id,
                    'store_slug' => $storeSlug,
                ]);
                
                return redirect()->route('bulk.checkout', ['store_slug' => $storeSlug]);
            }
            
            // Check if coming from regular checkout
            if (session('checkout_redirect')) {
                $storeSlug = session('checkout_store_slug');
                session()->forget(['checkout_redirect', 'checkout_store_slug']);
                
                Log::info('customer.login.checkout_redirect', [
                    'customer_id' => $customer->id,
                    'store_slug' => $storeSlug,
                ]);
                
                return redirect()->route('checkout.index', ['store_slug' => $storeSlug]);
            }
            
            return redirect()->intended(route('account.dashboard'));
        }

        Log::warning('customer.login.failed', ['email' => $request->email]);
        
        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Merge guest cart with customer cart after login
     */
    protected function mergeGuestCart(Request $request, Customer $customer): void
    {
        $guestToken = $request->cookie('guest_token');
        
        if (!$guestToken) {
            Log::info('cart_merge.no_guest_token', ['customer_id' => $customer->id]);
            return;
        }

        try {
            DB::transaction(function () use ($guestToken, $customer) {
                // Find all guest carts (user_id is null for guests)
                $guestCarts = Cart::where('guest_token', $guestToken)
                    ->whereNull('user_id')
                    ->with('items')
                    ->get();

                if ($guestCarts->isEmpty()) {
                    Log::info('cart_merge.no_guest_carts', [
                        'customer_id' => $customer->id,
                        'guest_token' => $guestToken
                    ]);
                    return;
                }

                foreach ($guestCarts as $guestCart) {
                    // Find active cart for this customer/store or create a fresh one
                    $customerCart = Cart::where('user_id', $customer->id)
                        ->where('store_id', $guestCart->store_id)
                        ->where('status', 'active')
                        ->first();

                    if (!$customerCart) {
                        $customerCart = Cart::create([
                            'store_id' => $guestCart->store_id,
                            'user_id' => $customer->id,
                            'currency' => $guestCart->currency,
                            'status' => 'active',
                            'guest_token' => null,
                        ]);
                    }

                    // Merge cart items
                    foreach ($guestCart->items as $guestItem) {
                        $existingQuery = $customerCart->items()
                            ->where('product_id', $guestItem->product_id);

                        if ($guestItem->variant_key !== null) {
                            $existingQuery->where('variant_key', $guestItem->variant_key);
                        } else {
                            $existingQuery->whereNull('variant_key');
                        }

                        $existingItem = $existingQuery->first();

                        if ($existingItem) {
                            // Update quantity if item already exists
                            $existingItem->qty += $guestItem->qty;
                            $existingItem->save();
                            
                            Log::info('cart_merge.item_updated', [
                                'customer_id' => $customer->id,
                                'product_id' => $guestItem->product_id,
                                'old_qty' => $existingItem->qty - $guestItem->qty,
                                'new_qty' => $existingItem->qty,
                            ]);
                        } else {
                            // Move item to customer cart
                            $guestItem->cart_id = $customerCart->id;
                            $guestItem->save();
                            
                            Log::info('cart_merge.item_moved', [
                                'customer_id' => $customer->id,
                                'product_id' => $guestItem->product_id,
                                'qty' => $guestItem->qty,
                            ]);
                        }
                    }

                    // Recalculate totals on the customer cart after merging
                    $customerCart->load('items');
                    $customerCart->recalcTotals();

                    // Delete empty guest cart
                    $guestCart->delete();
                    
                    Log::info('cart_merge.guest_cart_deleted', [
                        'customer_id' => $customer->id,
                        'store_id' => $guestCart->store_id,
                        'items_count' => $guestCart->items->count(),
                    ]);
                }

                Log::info('cart_merge.completed', [
                    'customer_id' => $customer->id,
                    'guest_token' => $guestToken,
                    'carts_merged' => $guestCarts->count(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('cart_merge.failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle logout
     */
    public function logout(Request $request): RedirectResponse
    {
        Log::info('customer.logout', ['customer_id' => Auth::guard('customer')->id()]);
        
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('account.login');
    }

    /**
     * Show password reset request form
     */
    public function showForgotPassword(): View
    {
        return view('account.forgot-password');
    }

    /**
     * Send password reset OTP
     */
    public function sendResetOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return back()->withErrors(['email' => 'User not found.']);
        }

        // Generate 6-digit OTP
        $otp = (string) random_int(100000, 999999);
        $cacheKey = 'password_reset_otp:' . $user->email;
        
        // Store OTP for 10 minutes
        Cache::put($cacheKey, $otp, now()->addMinutes(10));

        try {
            Mail::to($user->email)->queue(new PasswordResetOtpMail($user, $otp));
            Log::info('password_reset.otp_sent', ['user_id' => $user->id, 'email' => $user->email]);
        } catch (\Throwable $e) {
            Log::error('password_reset.otp_failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['email' => 'Failed to send OTP. Please try again.']);
        }

        return redirect()->route('account.reset-password.verify')
            ->with('email', $user->email)
            ->with('success', 'OTP sent to your email address.');
    }

    /**
     * Show OTP verification form
     */
    public function showVerifyOtp(): View
    {
        if (!session('email')) {
            return redirect()->route('account.forgot-password');
        }
        
        return view('account.verify-reset-otp');
    }

    /**
     * Verify OTP and show password reset form
     */
    public function verifyOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $cacheKey = 'password_reset_otp:' . $request->email;
        $storedOtp = Cache::get($cacheKey);

        if (!$storedOtp || $storedOtp !== $request->otp) {
            Log::warning('password_reset.otp_invalid', ['email' => $request->email]);
            return back()->withErrors(['otp' => 'Invalid or expired OTP.']);
        }

        // OTP is valid, store token for password reset
        $token = bin2hex(random_bytes(32));
        Cache::put('password_reset_token:' . $token, $request->email, now()->addMinutes(30));
        Cache::forget($cacheKey); // Remove used OTP

        Log::info('password_reset.otp_verified', ['email' => $request->email]);

        return redirect()->route('account.reset-password.form', ['token' => $token]);
    }

    /**
     * Show password reset form
     */
    public function showResetPassword(string $token): View
    {
        $email = Cache::get('password_reset_token:' . $token);
        
        if (!$email) {
            abort(403, 'Invalid or expired reset token.');
        }

        return view('account.reset-password', compact('token', 'email'));
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request, string $token): RedirectResponse
    {
        $email = Cache::get('password_reset_token:' . $token);
        
        if (!$email) {
            return redirect()->route('account.forgot-password')
                ->withErrors(['email' => 'Invalid or expired reset token.']);
        }

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $email)->first();
        
        if (!$user) {
            return redirect()->route('account.forgot-password')
                ->withErrors(['email' => 'User not found.']);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        Cache::forget('password_reset_token:' . $token);

        Log::info('password_reset.completed', ['user_id' => $user->id, 'email' => $user->email]);

        return redirect()->route('account.login')
            ->with('success', 'Password reset successfully. Please login with your new password.');
    }

    /**
     * Show account dashboard
     */
    public function dashboard(): View
    {
        $customer = Auth::guard('customer')->user();
        
        $stats = [
            'total_orders' => Order::where('customer_id', $customer->id)->count(),
            'pending_orders' => Order::where('customer_id', $customer->id)->where('status', 'pending')->count(),
            'completed_orders' => Order::where('customer_id', $customer->id)->where('status', 'completed')->count(),
            'total_spent' => Transaction::whereHas('order', function($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })->where('status', 'completed')->sum('amount'),
        ];

        $recentOrders = Order::where('customer_id', $customer->id)
            ->with(['store', 'items'])
            ->latest()
            ->take(5)
            ->get();

        return view('account.dashboard', compact('customer', 'stats', 'recentOrders'));
    }

    /**
     * Show account info page
     */
    public function showAccountInfo(): View
    {
        $customer = Auth::guard('customer')->user();

        return view('account.info', compact('customer'));
    }

    /**
     * Update account info
     */
    public function updateAccountInfo(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:190'],
            'last_name' => ['required', 'string', 'max:190'],
            'phone' => ['required', 'string', 'max:50'],
        ]);

        // Update customer
        $customer->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
        ]);

        Log::info('account.info.updated', ['customer_id' => $customer->id]);

        return back()->with('success', 'Account information updated successfully.');
    }

    /**
     * Show orders page
     */
    public function orders(Request $request): View
    {
        $customer = Auth::guard('customer')->user();

        $query = Order::where('customer_id', $customer->id)
            ->with(['store', 'items.product']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search by order number
        if ($request->filled('search')) {
            $query->where('order_number', 'like', '%' . $request->search . '%');
        }

        $orders = $query->latest()->paginate(10);

        return view('account.orders', compact('orders'));
    }

    /**
     * Show single order
     */
    public function showOrder(string $orderNumber): View
    {
        $customer = Auth::guard('customer')->user();

        $order = Order::where('order_number', $orderNumber)
            ->where('customer_id', $customer->id)
            ->with(['store', 'items.product', 'transactions.paymentMethod'])
            ->firstOrFail();

        return view('account.order-details', compact('order'));
    }

    /**
     * Show transactions page
     */
    public function transactions(Request $request): View
    {
        $customer = Auth::guard('customer')->user();

        $query = Transaction::whereHas('order', function($q) use ($customer) {
            $q->where('customer_id', $customer->id);
        })->with(['order', 'paymentMethod']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->latest()->paginate(10);

        return view('account.transactions', compact('transactions'));
    }

    /**
     * Show single transaction
     */
    public function showTransaction(string $transactionId): View
    {
        $customer = Auth::guard('customer')->user();

        $transaction = Transaction::where('reference', $transactionId)
            ->whereHas('order', function($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })
            ->with(['order.customer', 'order.store', 'paymentMethod'])
            ->firstOrFail();

        return view('account.transaction-details', compact('transaction'));
    }
}
