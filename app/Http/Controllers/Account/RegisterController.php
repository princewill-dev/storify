<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Mail\Shop4meOtpMail;
use App\Mail\OtpMail;
use App\Models\Cart;
use App\Models\Shop4meRequest;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Services\OtpService;

class RegisterController extends Controller
{
    // GET /shop4me/{list}/register
    public function showRegister(Request $request, ?string $list = null)
    {
        // Preserve bulk buy redirect if coming from bulk buy checkout
        if ($request->has('flow') && $request->flow == 'bulk-buy') {
            if (!session('bulk_buy_redirect')) {
                session([
                    'bulk_buy_redirect' => true,
                    'bulk_buy_store_slug' => $request->get('store_slug', session('bulk_buy_store_slug')),
                ]);
            }
        }
        // Preserve family-pack redirect if coming from family pack checkout
        if ($request->has('flow') && $request->flow == 'family_pack') {
            if (!session('family_pack_redirect')) {
                session([
                    'family_pack_redirect' => true,
                    'family_pack_store_slug' => $request->get('store_slug', session('family_pack_store_slug')),
                ]);
            }
        }
        
        // Preserve live-first redirect if coming from live first enrollment
        if ($request->has('flow') && $request->flow == 'live-first') {
            if (!session('live_first_redirect')) {
                session([
                    'live_first_redirect' => true,
                    'live_first_store_slug' => $request->get('store_slug', session('live_first_store_slug')),
                ]);
            }
        }
        
        // Preserve checkout redirect if coming from checkout
        if ($request->has('checkout') && $request->checkout == '1') {
            // Session should already be set by middleware, but ensure it's there
            if (!session('checkout_redirect')) {
                session([
                    'checkout_redirect' => true,
                    'checkout_store_slug' => $request->get('store_slug', session('checkout_store_slug')),
                ]);
            }
        }

        // If list provided, ensure it exists; else render generic registration
        if ($list) {
            $req = Shop4meRequest::where('list_id', $list)->firstOrFail();

            if (auth()->guard('customer')->check()) {
                $customer = auth()->guard('customer')->user();

                if (!$req->customer_id) {
                    $req->customer_id = $customer->id;
                    $req->save();
                    Log::info('shop4me.customer.auto_linked', ['customer_id' => $customer->id, 'list_id' => $req->list_id]);
                } elseif ((int) $req->customer_id !== (int) $customer->id) {
                    abort(403, 'This SHOP4ME request belongs to another customer.');
                }

                return redirect()->route('shop4me.checkout', ['list' => $req->list_id]);
            }
            return view('account.register', ['listId' => $req->list_id]);
        }
        
        $flow = $request->query('flow');
        return view('account.register', ['listId' => null, 'flow' => $flow]);
    }

    public function resendOtp(Request $request, ?string $list = null)
    {
        $request->validate([
            'identifier' => ['nullable','string','max:190'],
        ]);

        $cooldownSeconds = 60;
        $lastSent = session('customer_otp_last_sent_at');
        if ($lastSent && (time() - $lastSent) < $cooldownSeconds) {
            $remaining = $cooldownSeconds - (time() - $lastSent);
            return back()->withErrors(['otp' => 'Please wait ' . $remaining . ' seconds before requesting a new code.']);
        }

        if ($list) {
            $req = Shop4meRequest::where('list_id', $list)->firstOrFail();
            $customer = $req->customer;
            if (!$customer) {
                return back()->withErrors(['otp' => 'No customer linked to this request. Please register again.']);
            }

            try {
                $otp = OtpService::generate($customer->email, 'shop4me');
                Mail::to($customer->email)->queue(new Shop4meOtpMail($req->list_id, $otp->code));
                session(['customer_otp_last_sent_at' => time()]);
                Log::info('shop4me.otp.resent', ['list_id' => $req->list_id, 'customer_id' => $customer->id]);
            } catch (\Throwable $e) {
                Log::error('shop4me.otp.resend_failed', ['list_id' => $req->list_id, 'error' => $e->getMessage()]);
                return back()->withErrors(['otp' => 'Unable to resend code. Please try again.']);
            }

            return back()->with('status', 'A new verification code has been sent to your email.');
        }

        $email = session('pending_customer_email');
        if (!$email) {
            return back()->withErrors(['otp' => 'Session expired. Please register again.']);
        }

        try {
            $otp = OtpService::generate($email, 'account');
            Mail::to($email)->queue(new OtpMail($otp->code));
            session(['customer_otp_last_sent_at' => time()]);
            Log::info('account.otp.resent', ['email' => $email]);
        } catch (\Throwable $e) {
            Log::error('account.otp.resend_failed', ['email' => $email, 'error' => $e->getMessage()]);
            return back()->withErrors(['otp' => 'Unable to resend code. Please try again.']);
        }

        return back()->with('status', 'A new verification code has been sent to your email.');
    }

    // POST /shop4me/{list}/register
    public function register(Request $request, ?string $list = null)
    {
        $data = $request->validate([
            'name' => ['required','string','max:190'],
            'email' => ['required','email','max:190','unique:users,email','unique:customers,email'],
            'phone' => ['required','string','max:50'],
            'password' => ['required','string','min:8','confirmed'],
        ], [
            'email.unique' => 'This email already has an account. Please login or use Forgot Password to set up your account.',
        ]);

        // Split name into first and last name
        $nameParts = explode(' ', $data['name'], 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        // Create customer
        $customer = Customer::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']), // Hash the user's password
            'ip_address' => $request->ip(),
        ]);
        
        Log::info('account.customer.registered', ['customer_id' => $customer->id, 'email' => $customer->email]);

        // Generate OTP and branch by flow
        $otpRecord = OtpService::generate($customer->email, $list ? 'shop4me' : 'account');
        $otp = $otpRecord->code;

        // Store customer ID and email in session for OTP verification
        session([
            'pending_customer_id' => $customer->id,
            'pending_customer_email' => $customer->email
        ]);
        
        // Check if coming from checkout
        $fromCheckout = session('checkout_redirect');
        
        if ($list) {
            $req = Shop4meRequest::where('list_id', $list)->firstOrFail();
            $req->customer_id = $customer->id;
            $req->save();
            Log::info('shop4me.customer.linked', ['customer_id' => $customer->id, 'list_id' => $req->list_id]);
            try {
                Mail::to($customer->email)->queue(new Shop4meOtpMail($req->list_id, $otp));
                Log::info('customer.email.otp_sent', ['list_id' => $req->list_id, 'email' => $customer->email]);
            } catch (\Throwable $e) {
                Log::warning('email.shop4me_otp.failed', ['error' => $e->getMessage()]);
            }
            session(['customer_otp_last_sent_at' => time()]);
            return redirect()->route('account.verify', ['list' => $req->list_id]);
        } else {
            try {
                Mail::to($customer->email)->queue(new OtpMail($otp));
                Log::info('customer.email.otp_sent', ['flow' => 'account', 'email' => $customer->email]);
            } catch (\Throwable $e) {
                Log::warning('email.account_otp.failed', ['error' => $e->getMessage()]);
            }
            session(['customer_otp_last_sent_at' => time()]);

            $params = ['email' => $customer->email];
            if ($request->filled('checkout_code')) {
                $params['checkout_code'] = $request->checkout_code;
                $params['store'] = $request->store;
            }
            if (session('bulk_buy_redirect')) $params['flow'] = 'bulk-buy';
            if (session('family_pack_redirect')) $params['flow'] = 'family_pack';
            if (session('live_first_redirect')) $params['flow'] = 'live-first';

            return redirect()->route('account.verify', $params);
        }
    }

    // GET /shop4me/{list}/verify
    public function showVerify(Request $request, ?string $list = null)
    {
        $flow = $request->query('flow');
        $email = $request->query('email', session('pending_customer_email', 'your email'));
        $checkoutCode = $request->query('checkout_code');
        $checkoutStore = $request->query('store');
        return view('account.otp', ['listId' => $list, 'flow' => $flow, 'email' => $email, 'checkoutCode' => $checkoutCode, 'checkoutStore' => $checkoutStore]);
    }

    // POST /shop4me/{list}/verify
    public function verify(Request $request, ?string $list = null)
    {
        $data = $request->validate([
            'email' => ['nullable', 'email'],
            'otp' => ['required','digits:6'],
        ]);
        
        if ($list) {
            $req = Shop4meRequest::where('list_id', $list)->firstOrFail();
            $email = optional($req->customer)->email ?? $request->input('email');
            if (!$email) {
                return back()->withErrors(['email' => 'Email unknown for this list.']);
            }
            $verified = OtpService::verify($email, $data['otp'], 'shop4me');
            if (!$verified) {
                Log::info('customer.email.otp_failed', ['list_id' => $req->list_id, 'email' => $email]);
                return back()->withErrors(['otp' => 'Invalid or expired OTP.']);
            }
            // Mark as verified on customer
            $customer = $req->customer;
            if ($customer && !$customer->hasVerifiedEmail()) {
                $customer->markEmailAsVerified();
            }
            
            // Log in the customer
            Auth::guard('customer')->login($customer);
            
            // Merge guest cart
            $this->mergeGuestCart($request, $customer);
            
            Log::info('customer.email.verified', ['list_id' => $req->list_id, 'customer_id' => $customer?->id]);
            return redirect()->route('shop4me.checkout', ['list' => $req->list_id]);
        } else {
            // Get email from form submission, fallback to session
            $email = $request->input('email', session('pending_customer_email'));
            if (!$email) {
                return back()->withErrors(['otp' => 'Session expired. Please register again.']);
            }
            
            $verified = OtpService::verify($email, $data['otp'], 'account');
            if (!$verified) {
                Log::info('customer.email.otp_failed', ['flow' => 'account', 'email' => $email]);
                return back()->withErrors(['otp' => 'Invalid or expired OTP.']);
            }
            $customer = Customer::where('email', $email)->first();
            if ($customer && !$customer->hasVerifiedEmail()) {
                $customer->markEmailAsVerified();
            }
            
            // Log in the customer
            Auth::guard('customer')->login($customer);
            
            // Merge guest cart
            $this->mergeGuestCart($request, $customer);
            
            Log::info('customer.email.verified', ['flow' => 'account', 'customer_id' => $customer?->id]);
            
            // Clear pending customer session data
            session()->forget(['pending_customer_id', 'pending_customer_email']);

            // Check if coming from checkout (URL-based, no session)
            if ($request->filled('checkout_code') && $request->filled('store')) {
                // Guest cart was merged/deleted — find/generate token for customer's active cart
                $activeCart = \App\Models\Cart::where('user_id', $customer->id)
                    ->where('status', 'active')
                    ->latest()->first();
                if ($activeCart && !$activeCart->checkout_token) {
                    $activeCart->update(['checkout_token' => \Illuminate\Support\Str::random(32)]);
                }
                $token = $activeCart?->checkout_token ?? $request->checkout_code;
                $storeSlug = $request->store;
                return redirect()->route('checkout.index', ['store_subdomain' => $storeSlug, 'token' => $token]);
            }

            return redirect()->route('account.dashboard');
        }
    }

    /**
     * Merge guest cart with customer cart after registration/login
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
                    // Find or create customer cart for this store
                    $customerCart = Cart::firstOrCreate(
                        [
                            'user_id' => $customer->id,
                            'store_id' => $guestCart->store_id,
                        ],
                        [
                            'guest_token' => null,
                        ]
                    );

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
}
