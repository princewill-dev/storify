<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Store;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class StoreServiceController extends Controller
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
        
        $servicesQuery = Service::query()
            ->with(['images', 'currency', 'store'])
            ->where('store_id', $store->id)
            ->where('status', 'active');

        if ($q !== '') {
            $servicesQuery->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('service_code', 'like', "%$q%");
            });
        }

        $services = $servicesQuery->latest()->paginate(12)->withQueryString();

        // Log view
        try {
             ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'view_store_services_page',
                'description' => 'Viewed store services page',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 255),
                'metadata' => [
                    'store_id' => $store->id,
                    'q' => $q,
                ],
            ]);
        } catch (\Throwable $e) {}

        return view('storefront.pages.services', compact('store', 'services', 'q'));
    }
}
