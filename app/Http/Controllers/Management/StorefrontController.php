<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\StoreAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class StorefrontController extends Controller
{
    public function __construct(private readonly StoreAccessService $access) {}

    public function create(Request $request, Store $store): View|RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        if ($store->has_website) {
            return redirect()->route('management.stores.show', $store)->with('info', 'This store already has a storefront.');
        }

        $user = $request->user();
        $templates = [[
            'id' => 'basic',
            'name' => 'Basic',
            'description' => 'Clean storefront with a searchable product grid and category filters.',
            'color' => '#2563eb',
        ]];
        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name, 'url' => route('management.stores.show', $store)],
            ['label' => 'Create Storefront'],
        ];

        return view('management.stores.storefront.create', compact('user', 'store', 'templates', 'breadcrumbs'));
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        if ($store->has_website) {
            return redirect()->route('management.stores.show', $store)->with('info', 'This store already has a storefront.');
        }

        $data = $this->validateStorefront($request, $store, true);
        $this->enable($request, $store, $data);

        return redirect()->route('management.stores.show', $store)
            ->with('success', 'Storefront created at '.$store->slug.'.'.config('app.main_domain', 'storify.ng'));
    }

    public function enableWebsite(Request $request, Store $store): RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        $data = $this->validateStorefront($request, $store, false);
        $this->enable($request, $store, $data);

        return back()->with('success', 'Web storefront enabled at '.$store->slug.'.'.config('app.main_domain', 'storify.ng'));
    }

    private function validateStorefront(Request $request, Store $store, bool $withTemplate): array
    {
        $rules = [
            'store_name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:stores,slug,'.$store->id],
            'is_nationwide' => ['boolean'],
            'nationwide_fee' => ['nullable', 'numeric', 'min:0'],
            'nationwide_days' => ['nullable', 'integer', 'min:1'],
        ];
        if ($withTemplate) {
            $rules['template'] = ['required', 'in:basic'];
        }

        return $request->validate($rules);
    }

    private function enable(Request $request, Store $store, array $data): void
    {
        $store->update(['name' => $data['store_name'], 'slug' => $data['slug'], 'has_website' => true]);

        if ($request->boolean('is_nationwide')) {
            $store->deliveryRoutes()->updateOrCreate(
                ['state' => 'All States', 'country' => 'Nigeria'],
                [
                    'business_id' => $store->business_id,
                    'country' => 'Nigeria',
                    'state' => 'All States',
                    'area' => null,
                    'fee' => (int) ($data['nationwide_fee'] ?? 0) * 100,
                    'delivery_days' => (int) ($data['nationwide_days'] ?? 3),
                    'active' => true,
                ]
            );
        }
    }
}
