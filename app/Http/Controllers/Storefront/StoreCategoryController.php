<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\DB;

class StoreCategoryController extends Controller
{
    public function index(Request $request, string $store_subdomain, string $category_slug)
    {
        // Get store from subdomain
        $store = Store::where('slug', $store_subdomain)
            ->whereNotIn('status', ['deleted'])
            ->firstOrFail();

        // Check if store is pending - consistent with other controllers
        if ($store->status === 'pending') {
             return redirect()->route('home.store.products.index', ['store_subdomain' => $store->slug]);
        }

        // Only active stores
        if ($store->status !== 'active') {
            abort(404);
        }

        // Find Category
        $category = Category::where('store_id', $store->id)
            ->where('slug', $category_slug)
            ->where('status', 'active')
            ->firstOrFail();

        // Filters
        $q = trim((string) $request->query('q', ''));
        
        $productsQuery = Product::query()
            ->with(['images', 'variants', 'category', 'store'])
            ->where('store_id', $store->id)
            ->where('category_id', $category->id)
            ->where('status', 'active'); // Only active products for category view usually

        if ($q !== '') {
            $productsQuery->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('slug', 'like', "%$q%")
                  ->orWhere('product_code', 'like', "%$q%");
            });
        }

        $products = $productsQuery->latest()->paginate(12)->withQueryString();

        // reuse price calculation logic from ProductController is ideal, but for now I will copy the essential parts 
        // to ensure display consistency without tight coupling to a massive controller.
        // In a real refactor, this should be a service or model accessor.

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
            } else {
                $sym = $currencySymbols[$p->currency_id ?? 0] ?? $fallbackSym;
                $amt = (float)($p->amount ?? 0);
                $discPct = (float)($p->discount_percentage ?? 0);
                if ($discPct > 0) {
                    $disc = $amt * (1 - ($discPct/100));
                    $p->display_price = $sym . number_format($disc, 2);
                    $p->display_price_was = $sym . number_format($amt, 2);
                } else {
                    $p->display_price = $sym . number_format($amt, 2);
                    $p->display_price_was = null;
                }
            }
            return $p;
        });

        // Log view
        try {
             ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'view_store_category',
                'description' => 'Viewed store category: ' . $category->name,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 255),
                'metadata' => [
                    'store_id' => $store->id,
                    'category_id' => $category->id,
                    'category_slug' => $category->slug,
                ],
            ]);
        } catch (\Throwable $e) {}

        return view('storefront.pages.category', compact('store', 'category', 'products', 'q'));
    }
}
