<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\StoreAccessService;
use App\Services\StoreAnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class StoreDashboardController extends Controller
{
    public function __construct(
        private readonly StoreAccessService $access,
        private readonly StoreAnalyticsService $analytics,
    ) {}

    public function show(Request $request, Store $store): View
    {
        $this->access->authorize($request->user(), $store);
        $store->load(['business', 'posSessions']);
        $user = $request->user();
        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name],
        ];

        return view('management.stores.show', [
            'user' => $user,
            'store' => $store,
            'breadcrumbs' => $breadcrumbs,
            ...$this->analytics->dashboard($store),
        ]);
    }

    public function webMetrics(Request $request, Store $store): View|RedirectResponse
    {
        $this->access->authorize($request->user(), $store);
        if (! $store->has_website) {
            return redirect()->route('management.stores.show', $store)->with('error', 'This store does not have an online presence.');
        }

        $user = $request->user();
        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name, 'url' => route('management.stores.show', $store)],
            ['label' => 'Web Store'],
        ];

        return view('management.stores.web-metrics', [
            'user' => $user,
            'store' => $store,
            'breadcrumbs' => $breadcrumbs,
            ...$this->analytics->web($store),
        ]);
    }
}
