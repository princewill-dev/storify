<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

class StoreProductController extends Controller
{
    public function index(Request $request, string $store_subdomain)
    {
        // Get store from subdomain
        $store = Store::where('slug', $store_subdomain)
            ->whereNotIn('status', ['deleted'])
            ->firstOrFail();

        // Check pending status
        if ($store->status === 'pending') {
             return redirect()->route('home.store.products.index', ['store_subdomain' => $store->slug]);
        }

        if ($store->status !== 'active') {
            abort(404);
        }

        // Filters
        $q = trim((string) $request->query('q', ''));
        
        $productsQuery = Product::query()
            ->with(['images', 'variants', 'category', 'store'])
            ->where('store_id', $store->id)
            ->where('status', 'active');

        if ($q !== '') {
            $productsQuery->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('slug', 'like', "%$q%")
                  ->orWhere('product_code', 'like', "%$q%");
            });
        }

        $products = $productsQuery->latest()->paginate(12)->withQueryString();

        // Price formatting logic (helper reuse simulation)
        $currencySymbols = [];
        try {
            foreach (DB::table('currencies')->select('id','symbol')->get() as $r) { $currencySymbols[$r->id] = $r->symbol; }
        } catch (\Throwable $e) {}
        
        $fallbackSym = '';
        try { $fallbackSym = (string)(View::shared('company')->currency_symbol ?? ''); } catch (\Throwable $e) {}

        $products->getCollection()->transform(function($p) use ($currencySymbols, $fallbackSym) {
            if ($p->has_variants && $p->variants && $p->variants->count() > 0) {
                $minVar = $p->variants->sortBy('amount')->first();
                $sym = $currencySymbols[$minVar->currency_id ?? 0] ?? $fallbackSym;
                $p->display_price = $sym . number_format((float)$minVar->amount, 2);
                $p->display_price_was = null;
                 $p->price_currency_symbol = $sym;
            } else {
                $sym = $currencySymbols[$p->currency_id ?? 0] ?? $fallbackSym;
                $amt = (float)($p->amount ?? 0);
                $discPct = (float)($p->discount_percentage ?? 0);
                if ($discPct > 0) {
                    $disc = $amt * (1 - ($discPct/100));
                    $p->display_price = $sym . number_format($disc, 2);
                    $p->display_price_was = $sym . number_format($amt, 2);
                     $p->price_currency_symbol = $sym;
                } else {
                    $p->display_price = $sym . number_format($amt, 2);
                    $p->display_price_was = null;
                     $p->price_currency_symbol = $sym;
                }
            }
            return $p;
        });

        // Log view
        try {
             ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'view_store_products_page',
                'description' => 'Viewed store products page',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 255),
                'metadata' => [
                    'store_id' => $store->id,
                    'q' => $q,
                ],
            ]);
        } catch (\Throwable $e) {}

        return view('storefront.pages.products', compact('store', 'products', 'q'));
    }
}
