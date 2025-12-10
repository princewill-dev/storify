<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Mail\AdminBulkOrderAcceptedMail;
use App\Mail\AdminNewBulkOrderMail;
use App\Mail\BulkOrderUnderReviewMail;
use App\Models\BulkOrder;
use App\Models\BulkOrderItem;
use App\Models\DeliveryAddress;
use App\Models\Product;
use App\Models\Store;
use App\Models\DeliveryRoute;
use App\Models\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Enums\BulkOrderStatus;

class BulkOrderController extends Controller
{
    /**
     * Display bulk products page for a store
     */
    public function index($store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        
        // Get products that have bulk pricing (bulk_quantity > 0)
        $products = Product::where('store_id', $store->id)
            ->where('status', 'active')
            ->where('bulk_quantity', '>', 0)
            ->with(['images', 'store', 'currency'])
            ->paginate(12);
        
        return view('home.pages.bulk.index', compact('store', 'products'));
    }

    /**
     * Show checkout page with delivery address selection
     */
    /**
     * Show checkout page with delivery address selection
     */
    public function checkout($store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);

        // Check if cart is empty
        if (empty($cart['items']) && empty($cart['custom_items'])) {
            return redirect()->route('home.store.bulk_buy', $store_slug)->with('error', 'Your bulk cart is empty.');
        }

        $customer = Auth::guard('customer')->user();
        
        // Redirect to bulk buy auth flow if not authenticated
        if (!$customer) {
            session([
                'bulk_buy_redirect' => true,
                'bulk_buy_store_slug' => $store_slug,
            ]);
            return redirect()->route('account.register', ['flow' => 'bulk-buy'])
                ->with('info', 'Please register or log in to continue with bulk buy checkout.');
        }
        
        // Get customer's delivery addresses
        $deliveryAddresses = DeliveryAddress::where('customer_id', $customer->id)
            ->with('deliveryRoute')
            ->get();

        // Calculate cart totals
        $subtotal = 0;
        foreach ($cart['items'] as $item) {
            $subtotal += $item['subtotal'];
        }

        foreach ($cart['custom_items'] as $item) {
            $subtotal += $item['budgeted_amount'];
        }

        // Get active delivery routes for address creation
        $deliveryRoutes = DeliveryRoute::where('active', true)->orderBy('state')->get();

        // Get VAT percentage
        $vat = Vat::current();
        $vatPercentage = $vat ? $vat->percentage : 0;

        // Group delivery routes by state for cascade selection
        $areasByState = [];
        $states = [];
        foreach ($deliveryRoutes as $route) {
            if (!in_array($route->state, $states)) {
                $states[] = $route->state;
            }
            $areasByState[$route->state][] = [
                'id' => $route->id,
                'area' => $route->area,
                'fee' => $route->fee,
                'days' => $route->delivery_days
            ];
        }

        // Build address dataset for saved addresses (for JavaScript)
        $addressDataset = [];
        foreach ($deliveryAddresses as $address) {
            $addressDataset[$address->id] = [
                'delivery_route_id' => $address->delivery_route_id,
                'delivery_fee' => optional($address->deliveryRoute)->fee,
                'delivery_days' => optional($address->deliveryRoute)->delivery_days,
                'delivery_state' => optional($address->deliveryRoute)->state,
                'delivery_area' => optional($address->deliveryRoute)->area,
            ];
        }

        Log::info('bulk_checkout_started', [
            'store' => $store_slug,
            'customer_id' => $customer->id,
            'items_count' => count($cart['items']),
            'custom_items_count' => count($cart['custom_items']),
            'subtotal' => $subtotal
        ]);

        return view('home.pages.bulk.checkout', compact(
            'cart',
            'deliveryAddresses',
            'subtotal',
            'store',
            'deliveryRoutes',
            'vatPercentage',
            'areasByState',
            'states',
            'addressDataset'
        ));
    }

    /**
     * Submit bulk order
     */
    public function submitOrder(Request $request, $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        
        $request->validate([
            'delivery_address_id' => 'required|exists:delivery_addresses,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $customer = Auth::guard('customer')->user();
        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);

        // Verify cart is not empty
        if (empty($cart['items']) && empty($cart['custom_items'])) {
            return redirect()->route('home.store.bulk_buy', $store_slug)->with('error', 'Your bulk cart is empty.');
        }

        // Verify delivery address belongs to customer
        $deliveryAddress = DeliveryAddress::where('id', $request->delivery_address_id)
            ->where('customer_id', $customer->id)
            ->with('deliveryRoute')
            ->firstOrFail();

        try {
            DB::beginTransaction();

            // Calculate totals
            $subtotal = 0;
            foreach ($cart['items'] as $item) {
                $subtotal += $item['subtotal'];
            }
            foreach ($cart['custom_items'] as $item) {
                $subtotal += $item['budgeted_amount'];
            }

            // Get delivery fee from the selected address's route
            $shippingFee = 0;
            if ($deliveryAddress->deliveryRoute) {
                $shippingFee = $deliveryAddress->deliveryRoute->fee; // Fee is in kobo
            }

            // Get VAT percentage
            $vat = \App\Models\Vat::current();
            $vatPercentage = $vat ? $vat->percentage : 0;

            // Calculate tax and total
            $shippingFeeNaira = $shippingFee / 100; // Convert kobo to naira
            $tax = ($subtotal + $shippingFeeNaira) * ($vatPercentage / 100);
            $estimatedTotal = $subtotal + $shippingFeeNaira + $tax;

            // Create bulk order
            $bulkOrder = BulkOrder::create([
                'bulk_code' => BulkOrder::generateBulkCode(),
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'delivery_address_id' => $deliveryAddress->id,
                'delivery_route_id' => $deliveryAddress->delivery_route_id,
                'status' => BulkOrderStatus::PENDING_REVIEW->value,
                'subtotal' => $subtotal,
                'estimated_total' => $estimatedTotal, // Now includes shipping and tax
                'notes' => $request->notes,
                'custom_items' => !empty($cart['custom_items']) ? $cart['custom_items'] : null,
            ]);

            // Create bulk order items from cart
            foreach ($cart['items'] as $item) {
                BulkOrderItem::create([
                    'bulk_order_id' => $bulkOrder->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['name'],
                    'product_code' => $item['product_code'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                    'is_custom' => false,
                ]);
            }

            // Create bulk order items from custom items
            foreach ($cart['custom_items'] as $customItem) {
                BulkOrderItem::create([
                    'bulk_order_id' => $bulkOrder->id,
                    'product_id' => null,
                    'product_name' => $customItem['name'],
                    'product_code' => null,
                    'quantity' => $customItem['quantity'],
                    'unit_price' => null,
                    'subtotal' => $customItem['budgeted_amount'],
                    'is_custom' => true,
                    'budgeted_amount' => $customItem['budgeted_amount'],
                ]);
            }

            Log::info('bulk_order_created', [
                'bulk_code' => $bulkOrder->bulk_code,
                'customer_id' => $customer->id,
                'store_id' => $store->id,
                'items_count' => count($cart['items']),
                'custom_items_count' => count($cart['custom_items']),
                'subtotal' => $subtotal,
                'delivery_address_id' => $deliveryAddress->id
            ]);

            // Send email to customer (queued)
            try {
                Mail::to($customer->email)->send(new BulkOrderUnderReviewMail($bulkOrder));
                
                Log::info('bulk_order_customer_email_sent', [
                    'bulk_code' => $bulkOrder->bulk_code,
                    'customer_email' => $customer->email
                ]);
            } catch (\Exception $e) {
                Log::error('bulk_order_customer_email_failed', [
                    'bulk_code' => $bulkOrder->bulk_code,
                    'error' => $e->getMessage()
                ]);
            }

            // Send email to admin (queued)
            try {
                $adminEmail = config('mail.from.address');
                Mail::to($adminEmail)->send(new AdminNewBulkOrderMail($bulkOrder));
                
                Log::info('bulk_order_admin_email_sent', [
                    'bulk_code' => $bulkOrder->bulk_code,
                    'admin_email' => $adminEmail
                ]);
            } catch (\Exception $e) {
                Log::error('bulk_order_admin_email_failed', [
                    'bulk_code' => $bulkOrder->bulk_code,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            // Clear bulk cart
            session()->forget('bulk_cart');

            return redirect()->route('bulk.order.confirmation', ['store_slug' => $store_slug, 'bulkCode' => $bulkOrder->bulk_code])
                ->with('success', 'Your bulk order has been submitted for review!');

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('bulk_order_creation_failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'Failed to submit bulk order. Please try again.')
                ->withInput();
        }
    }

    /**
     * Show order confirmation page
     */
    public function confirmation($store_slug, $bulkCode)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $customer = Auth::guard('customer')->user();
        
        $bulkOrder = BulkOrder::where('bulk_code', $bulkCode)
            ->where('customer_id', $customer->id)
            ->with(['items', 'deliveryAddress.deliveryRoute', 'store'])
            ->firstOrFail();

        return view('home.pages.bulk.confirmation', compact('bulkOrder', 'store'));
    }

    /**
     * Show bulk order for customer review (after admin changes)
     */
    public function review($store_slug, $bulkCode)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $customer = Auth::guard('customer')->user();
        
        $bulkOrder = BulkOrder::with(['customer', 'store', 'deliveryAddress', 'items.product'])
            ->where('bulk_code', $bulkCode)
            ->where('customer_id', $customer->id)
            ->firstOrFail();
        
        // Load all revisions for negotiation timeline
        $revisions = \App\Models\BulkOrderRevision::where('bulk_order_id', $bulkOrder->id)
            ->orderBy('revision_number', 'asc')
            ->get();
        
        // Check if customer can respond (last update was by admin)
        $canRespond = $bulkOrder->last_updated_by === 'admin';
        
        // Check if customer can accept (last update was by admin and not yet accepted)
        $canAccept = $bulkOrder->last_updated_by === 'admin' && !$bulkOrder->customer_accepted_at;
        
        Log::info('bulk_order.review_viewed', [
            'bulk_code' => $bulkOrder->bulk_code,
            'customer_id' => $customer->id
        ]);
        
        return view('home.pages.bulk.review', compact('bulkOrder', 'store', 'revisions', 'canRespond', 'canAccept'));
    }

    /**
     * Customer submits counter-proposal
     */
    public function submitResponse(Request $request, $store_slug, $bulkCode)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $customer = Auth::guard('customer')->user();
        
        $bulkOrder = BulkOrder::with(['items'])
            ->where('bulk_code', $bulkCode)
            ->where('customer_id', $customer->id)
            ->firstOrFail();
        
        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:bulk_order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.budgeted_amount' => 'nullable|numeric|min:0',
            'customer_notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $total = 0;

            // Update items
            foreach ($request->items as $itemData) {
                $item = BulkOrderItem::findOrFail($itemData['id']);
                
                if ($item->bulk_order_id !== $bulkOrder->id) {
                    continue;
                }

                $item->quantity = $itemData['quantity'];
                
                if ($item->is_custom) {
                    $item->budgeted_amount = $itemData['budgeted_amount'] ?? $item->budgeted_amount;
                    $item->subtotal = $item->budgeted_amount;
                } else {
                    // Customer can only change quantity, not price
                    $item->subtotal = $item->quantity * $item->unit_price;
                }
                
                $item->save();
                $total += $item->subtotal;
            }

            $bulkOrder->estimated_total = $total;
            $bulkOrder->subtotal = $total;
            $bulkOrder->notes = $request->customer_notes ?: $bulkOrder->notes;
            $bulkOrder->last_updated_by = 'customer';
            $bulkOrder->save();

            // Create revision record
            $revisionNumber = \App\Models\BulkOrderRevision::where('bulk_order_id', $bulkOrder->id)->max('revision_number') + 1;
            
            $itemsSnapshot = $bulkOrder->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_code' => $item->product_code,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'budgeted_amount' => $item->budgeted_amount,
                    'subtotal' => $item->subtotal,
                    'is_custom' => $item->is_custom,
                ];
            })->toArray();
            
            \App\Models\BulkOrderRevision::create([
                'bulk_order_id' => $bulkOrder->id,
                'revision_number' => $revisionNumber,
                'created_by_type' => 'customer',
                'created_by_id' => $customer->id,
                'notes' => $request->customer_notes,
                'items_snapshot' => $itemsSnapshot,
                'total_amount' => $total,
                'is_customer_accepted' => false,
            ]);

            DB::commit();

            // Notify admin
            try {
                // In a real app, you'd get the admin email from config or the store owner
                $adminEmail = 'admin@example.com'; 
                Mail::to($adminEmail)->queue(new \App\Mail\BulkOrderCustomerResponseMail($bulkOrder));
            } catch (\Exception $e) {
                Log::error('bulk_order.admin_notification_failed', [
                    'bulk_code' => $bulkOrder->bulk_code,
                    'error' => $e->getMessage()
                ]);
            }

            Log::info('bulk_order.customer_response_submitted', [
                'bulk_code' => $bulkOrder->bulk_code,
                'customer_id' => $customer->id,
                'revision_number' => $revisionNumber
            ]);

            return back()->with('success', 'Your response has been submitted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('bulk_order.customer_response_failed', [
                'bulk_code' => $bulkOrder->bulk_code,
                'customer_id' => $customer->id,
                'error' => $e->getMessage()
            ]);
            return back()->with('error', 'Failed to submit response. Please try again.');
        }
    }

    /**
     * Customer accepts the bulk order
     */
    public function acceptOrder(Request $request, $store_slug, $bulkCode)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        $customer = Auth::guard('customer')->user();
        
        $bulkOrder = BulkOrder::where('bulk_code', $bulkCode)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        // Only allow acceptance if last update was by admin
        if ($bulkOrder->last_updated_by !== 'admin') {
            return back()->with('error', 'You can only accept after admin reviews your response.');
        }

        try {
            DB::beginTransaction();

            $bulkOrder->customer_accepted_at = now();
            $bulkOrder->status = BulkOrderStatus::APPROVED;
            $bulkOrder->save();

            // Create acceptance revision
            $revisionNumber = \App\Models\BulkOrderRevision::where('bulk_order_id', $bulkOrder->id)->max('revision_number') + 1;
            
            $itemsSnapshot = $bulkOrder->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_code' => $item->product_code,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'budgeted_amount' => $item->budgeted_amount,
                    'subtotal' => $item->subtotal,
                    'is_custom' => $item->is_custom,
                ];
            })->toArray();
            
            \App\Models\BulkOrderRevision::create([
                'bulk_order_id' => $bulkOrder->id,
                'revision_number' => $revisionNumber,
                'created_by_type' => 'customer',
                'created_by_id' => $customer->id,
                'notes' => 'Customer accepted the order',
                'items_snapshot' => $itemsSnapshot,
                'total_amount' => $bulkOrder->estimated_total,
                'is_customer_accepted' => true,
            ]);

            DB::commit();

            Log::info('bulk_order.customer_accepted', [
                'bulk_code' => $bulkOrder->bulk_code,
                'customer_id' => $customer->id,
                'revision_number' => $revisionNumber
            ]);

            // Send email to admin about customer acceptance
            try {
                $adminEmail = config('mail.from.address');
                Mail::to($adminEmail)->queue(new AdminBulkOrderAcceptedMail($bulkOrder));
                
                Log::info('bulk_order.admin_acceptance_email_queued', [
                    'bulk_code' => $bulkOrder->bulk_code,
                    'admin_email' => $adminEmail
                ]);
            } catch (\Exception $e) {
                Log::error('bulk_order.admin_acceptance_email_failed', [
                    'bulk_code' => $bulkOrder->bulk_code,
                    'error' => $e->getMessage()
                ]);
            }

            return back()->with('success', 'Order accepted! We will finalize and send you a payment link soon.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('bulk_order.acceptance_failed', [
                'bulk_code' => $bulkOrder->bulk_code,
                'customer_id' => $customer->id,
                'error' => $e->getMessage()
            ]);
            return back()->with('error', 'Failed to accept order. Please try again.');
        }
    }
}
