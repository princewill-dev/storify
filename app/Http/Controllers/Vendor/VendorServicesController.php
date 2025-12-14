<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class VendorServicesController extends Controller
{
    private function resolveVendor(Request $request, Vendor $routeVendor): ?Vendor
    {
        $vendor = $request->user('vendor');
        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return null;
        }
        return $vendor;
    }

    private function vendorStoreIds(Vendor $vendor): array
    {
        return $vendor->stores()->pluck('id')->all();
    }

    public function index(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $status = strtolower((string)$request->query('status', ''));
        $q = trim((string)$request->query('q', ''));

        $storeIds = $this->vendorStoreIds($vendor);

        $query = Service::query()
            ->whereIn('store_id', $storeIds)
            ->with(['store', 'images', 'currency']);

        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                    ->orWhere('service_code', 'like', "%$q%");
            });
        }

        $perPage = (int) $request->query('per_page', 10);
        $services = $query->latest()->paginate($perPage)->withQueryString();

        $serviceImages = [];
        foreach ($services as $svc) {
            $Img = $svc->primaryImage();
            if ($Img && $Img->path) {
                $serviceImages[$svc->id] = asset('storage/' . $Img->path);
            }
        }

        return view('vendors.services.index', [
            'vendor' => $vendor,
            'services' => $services,
            'status' => $status,
            'q' => $q,
            'serviceImages' => $serviceImages,
        ]);
    }

    public function create(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $stores = $vendor->stores()->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');

        return view('vendors.services.create', compact('vendor', 'stores', 'currencies', 'defaultCurrencyId'));
    }

    public function store(Request $request, Vendor $routeVendor): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $request->validate([
            'store_id' => 'required',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $store = $vendor->stores()->where('id', $request->input('store_id'))->first();
        if (!$store) {
            return back()->with('error', 'Invalid store selected.');
        }

        try {
            $service = DB::transaction(function () use ($request, $store) {
                $data = $request->only(['store_id', 'name', 'description', 'amount', 'currency_id']);
                $data['status'] = 'active';
                $service = Service::create($data);

                if ($request->hasFile('images')) {
                    $pos = 0;
                    foreach ($request->file('images') as $idx => $file) {
                        $path = $file->store('services/images', 'public');
                        ServiceImage::create([
                            'service_id' => $service->id,
                            'path' => $path,
                            'is_primary' => (int)$request->input('primary_image') === $idx,
                            'position' => $pos++,
                        ]);
                    }
                    if (!$service->images()->where('is_primary', true)->exists()) {
                        $first = $service->images()->orderBy('position')->first();
                        if ($first) { $first->update(['is_primary' => true]); }
                    }
                }

                return $service;
            });

            ActivityLog::create([
                'user_id' => null,
                'action' => 'vendor_create_service',
                'description' => 'Vendor created a service',
                'metadata' => [
                    'vendor_id' => $vendor->id,
                    'service_id' => $service->id,
                ],
            ]);

            return redirect()->route('vendor.services.index', ['vendor' => $vendor])->with('success', 'Service created successfully.');
        } catch (QueryException $e) {
            Log::error('vendor.service.create_failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Unable to create service. Please try again.');
        }
    }

    public function edit(Request $request, Vendor $routeVendor, Service $service): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsService($service, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $stores = $vendor->stores()->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');
        $service->load('images');

        return view('vendors.services.edit', compact('vendor', 'service', 'stores', 'currencies', 'defaultCurrencyId'));
    }

    public function update(Request $request, Vendor $routeVendor, Service $service): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsService($service, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($request, $service) {
                $service->update($request->only(['name', 'description', 'amount', 'currency_id', 'status']));

                if ($request->filled('delete_image_ids')) {
                    $ids = $request->input('delete_image_ids');
                    $toDelete = $service->images()->whereIn('id', $ids)->get();
                    foreach ($toDelete as $img) {
                        try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
                        $img->delete();
                    }
                }

                if ($request->hasFile('images')) {
                    $pos = (int)$service->images()->max('position');
                    $pos = $pos < 0 ? 0 : $pos + 1;
                    foreach ($request->file('images') as $file) {
                        $path = $file->store('services/images', 'public');
                        ServiceImage::create([
                            'service_id' => $service->id,
                            'path' => $path,
                            'is_primary' => false,
                            'position' => $pos++,
                        ]);
                    }
                }

                if ($request->filled('primary_image_id')) {
                    $pid = (int)$request->input('primary_image_id');
                    $service->images()->update(['is_primary' => false]);
                    $service->images()->where('id', $pid)->update(['is_primary' => true]);
                }
                
                ActivityLog::create([
                    'user_id' => null,
                    'action' => 'vendor_update_service',
                    'description' => 'Vendor updated a service',
                    'metadata' => ['vendor_id' => $vendor->id, 'service_id' => $service->id],
                ]);
            });

            return redirect()->route('vendor.services.index', ['vendor' => $vendor])->with('success', 'Service updated.');
        } catch (\Throwable $e) {
            Log::error('vendor.service.update_failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Unable to update service.')->withInput();
        }
    }

    public function destroy(Request $request, Vendor $routeVendor, Service $service): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsService($service, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        foreach ($service->images as $img) {
            try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
        }
        $service->delete();
        return redirect()->route('vendor.services.index', ['vendor' => $vendor])->with('success', 'Service deleted.');
    }

    private function ownsService(Service $service, Vendor $vendor): bool
    {
        return in_array((int)$service->store_id, $this->vendorStoreIds($vendor), true);
    }
}
