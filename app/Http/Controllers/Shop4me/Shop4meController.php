<?php

namespace App\Http\Controllers\Shop4me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop4me\Shop4meDeliveryRequest;
use App\Http\Requests\Shop4me\Shop4meSubmitRequest;
use App\Mail\Shop4meListSubmitted;
use App\Models\Shop4meItem;
use App\Models\Shop4meRequest;
use App\Models\CompanyService;
use App\Models\Store;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class Shop4meController extends Controller
{
    // GET /{store}/shop4me landing page
    public function page(string $store_slug)
    {
        // Load the SHOP4ME company service to show its marketing card on the page
        $service = CompanyService::where('title', 'SHOP4ME')->first();
        return view('home.pages.shop4me.page', [
            'store_slug' => $store_slug,
            'service' => $service,
        ]);
    }
    // POST /shop4me/requests
    public function storeRequest(Shop4meSubmitRequest $request, string $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $data = $request->validated();

        // Server-side compute sum to persist (trust server only)
        $computedTotal = 0.0;
        foreach ($data['items'] as $it) { $computedTotal += (float)($it['amount_hint'] ?? 0); }
        $computedTotalFormatted = number_format($computedTotal, 2, '.', '');

        $req = null;
        DB::transaction(function () use (&$req, $data, $computedTotalFormatted, $store) {
            $req = Shop4meRequest::create([
                'store_id' => $store->id,
                'currency_id' => $data['currency_id'] ?? null,
                'budget_amount' => $computedTotalFormatted,
                'notes' => $data['notes'] ?? null,
                'status' => Shop4meRequest::STATUS_PENDING,
            ]);

            foreach ($data['items'] as $it) {
                Shop4meItem::create([
                    'shop4me_request_id' => $req->id,
                    'product_id' => $it['product_id'] ?? null,
                    'product_variant_id' => $it['product_variant_id'] ?? null,
                    'name' => $it['name'] ?? null,
                    'qty' => $it['qty'] ?? 1,
                    'unit_hint' => $it['unit_hint'] ?? null,
                    'amount_hint' => $it['amount_hint'] ?? null,
                    'notes' => $it['notes'] ?? null,
                    'allow_substitute' => $it['allow_substitute'] ?? true,
                ]);
            }
        });

        Log::info('shop4me.list.submitted', [
            'request_id' => $req->id,
            'list_id' => $req->list_id,
            'store_id' => $req->store_id,
        ]);

        // Notify admin (queued)
        try {
            $to = config('mail.from.address');
            if ($to) Mail::to($to)->queue(new Shop4meListSubmitted($req));
        } catch (\Throwable $e) {
            Log::warning('email.shop4me_list_submitted.failed', ['error' => $e->getMessage()]);
        }

        $cart = $this->prepareCartFromRequest($request, $req, $store);

        // Flag checkout redirect and store slug for post-auth redirection
        session([
            'checkout_redirect' => true,
            'checkout_store_slug' => $store->slug,
        ]);

        $next = route('account.register', ['list' => $req->list_id, 'checkout' => 1, 'store_slug' => $store->slug]);

        $response = response()->json([
            'list_id' => $req->list_id,
            'cart_id' => $cart->id,
            'next' => $next,
        ]);

        if (!$request->cookie('guest_token')) {
            $response->withCookie(cookie('guest_token', $cart->guest_token, 60 * 24 * 30));
        }

        return $response;
    }

    // GET /shop4me/{list}/track
    public function track(string $list)
    {
        $req = Shop4meRequest::where('list_id', $list)->firstOrFail();
        $events = \App\Models\Shop4meEvent::where('shop4me_request_id', $req->id)->latest()->get();
        return view('home.pages.tracking.order_status', ['request' => $req, 'events' => $events]);
    }

    // GET /shop4me/{list}/checkout
    public function checkout(Request $request, string $list)
    {
        $shop4meRequest = Shop4meRequest::with('store')->where('list_id', $list)->firstOrFail();
        $store = $shop4meRequest->store ?? ($shop4meRequest->store_id ? Store::find($shop4meRequest->store_id) : null);

        if (!$store) {
            abort(404, 'Store not found for this SHOP4ME request.');
        }

        $storeSlug = $store->slug;

        // Persist checkout redirect context for login flow
        session([
            'checkout_redirect' => true,
            'checkout_store_slug' => $storeSlug,
        ]);

        // Ensure authenticated customer
        if (!auth()->guard('customer')->check()) {
            return redirect()->route('account.login')->with('error', 'Please login to continue');
        }

        $customerId = auth()->guard('customer')->id();

        // Ensure request is linked to this customer
        if (empty($shop4meRequest->customer_id)) {
            $shop4meRequest->customer_id = $customerId;
            $shop4meRequest->save();
        } elseif ((int) $shop4meRequest->customer_id !== (int) $customerId) {
            abort(403, 'This SHOP4ME request belongs to another customer.');
        }

        return redirect()->route('checkout.index', ['store_slug' => $storeSlug]);
    }

    /**
     * Prepare a cart from the submitted Shop4me list so the customer can continue via checkout
     */
    private function prepareCartFromRequest(Request $request, Shop4meRequest $shop4meRequest, Store $store): Cart
    {
        $token = (string) $request->cookie('guest_token');
        if (!$token) {
            $token = Str::uuid()->toString();
            Cookie::queue('guest_token', $token, 60 * 24 * 30);
        }

        $customerId = auth()->guard('customer')->id();

        $cartQuery = Cart::query()
            ->where('store_id', $store->id)
            ->where('status', 'active');

        if ($customerId) {
            $cart = $cartQuery->where('user_id', $customerId)->first();
        } else {
            $cart = $cartQuery->where('guest_token', $token)->whereNull('user_id')->first();
        }

        if (!$cart) {
            $cart = Cart::create([
                'store_id' => $store->id,
                'user_id' => $customerId,
                'guest_token' => $customerId ? null : $token,
                'currency' => 'NGN',
                'status' => 'active',
                'meta' => ['source' => 'shop4me', 'shop4me_request_id' => $shop4meRequest->id],
            ]);
        } else {
            $meta = $cart->meta ?? [];
            $meta['source'] = 'shop4me';
            $meta['shop4me_request_id'] = $shop4meRequest->id;
            $cart->meta = $meta;
            if ($customerId && !$cart->user_id) {
                $cart->user_id = $customerId;
                $cart->guest_token = null;
            }
            $cart->save();
        }

        // Remove existing Shop4Me-derived items to avoid duplication
        $cart->items()
            ->where('meta->source', 'shop4me')
            ->delete();

        foreach ($shop4meRequest->items as $item) {
            $qty = (float) ($item->qty ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }

            $totalAmount = (float) ($item->amount_hint ?? 0);
            $unitAmount = $qty > 0 ? $totalAmount / $qty : $totalAmount;
            $unitAmountInt = (int) round($unitAmount * 100);
            $lineSubtotal = (int) round($unitAmountInt * $qty);

            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $item->product_id,
                'variant_key' => $item->product_variant_id ? (string)$item->product_variant_id : null,
                'name' => $item->name ?: 'Custom Item',
                'unit_amount' => $unitAmountInt,
                'qty' => $qty,
                'line_subtotal' => $lineSubtotal,
                'meta' => [
                    'source' => 'shop4me',
                    'shop4me_request_id' => $shop4meRequest->id,
                    'shop4me_item_id' => $item->id,
                    'unit_hint' => $item->unit_hint,
                    'notes' => $item->notes,
                    'allow_substitute' => $item->allow_substitute,
                ],
            ]);
        }

        $cart->load('items');
        $cart->recalcTotals();

        return $cart;
    }
}
