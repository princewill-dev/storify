<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Service;
use App\Models\ServiceImage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ServiceController extends Controller
{


    private function userStoreIds(User $user): array
    {
        return $user->stores()->where('status', '!=', 'deleted')->pluck('id')->all();
    }

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $storeIds = $this->userStoreIds($user);

        if (empty($storeIds)) {
            return view('management.services.index', [
                'user' => $user,
                'services' => collect(),
                'status' => '',
                'q' => '',
                'serviceImages' => [],
            ])->with('warning', 'You need at least one store to create services.');
        }
        $selectedPublicStoreId = $request->query('store_id');
        $selectedStoreId = null;
        $selectedStore = null;

        if ($selectedPublicStoreId) {
            $selectedStore = $user->stores()
                ->where('store_id', $selectedPublicStoreId)
                ->first();
            
            if ($selectedStore) {
                $selectedStoreId = $selectedStore->id;
            }
        }

        $query = Service::query()
            ->whereIn('store_id', $storeIds)
            ->with(['store', 'images', 'currency']);

        if ($selectedStoreId) {
            $query->where('store_id', $selectedStoreId);
        }

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

        return view('management.services.index', [
            'user' => $user,
            'services' => $services,
            'status' => $status,
            'q' => $q,
            'serviceImages' => $serviceImages,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $stores = $user->stores()->where('status', '!=', 'deleted')->orderBy('name')->get();

        if ($stores->isEmpty()) {
            return redirect()->route('management.services.index')
                ->with('warning', 'You need to create a store before adding services.');
        }
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');

        $selectedPublicStoreId = $request->query('store_id');
        $selectedStoreId = null;

        if ($selectedPublicStoreId) {
            $selectedStoreId = $user->stores()
                ->where('store_id', $selectedPublicStoreId)
                ->value('id');
        }

        return view('management.services.create', compact('user', 'stores', 'currencies', 'defaultCurrencyId', 'selectedStoreId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'store_id' => 'required',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $store = $user->stores()->where('id', $request->input('store_id'))->first();
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
                'user_id' => $user->id,
                'action' => 'service_created',
                'description' => 'Service created',
                'metadata' => [
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                ],
            ]);

            return redirect()->route('management.services.index', ['user' => $user, 'store_id' => $store->store_id])->with('success', 'Service created successfully.');
        } catch (QueryException $e) {
            Log::error('service.create_failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Unable to create service. Please try again.');
        }
    }

    public function edit(Request $request, Service $service): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsService($service, $user)) {
            return redirect()->route('management.auth.login');
        }

        $stores = $user->stores()->where('status', '!=', 'deleted')->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');
        $service->load('images', 'store'); // Eager load 'store' relationship
        $store = $service->store; // Resolve the store object

        return view('management.services.edit', compact('user', 'service', 'stores', 'currencies', 'defaultCurrencyId', 'store'));
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsService($service, $user)) {
            return redirect()->route('management.auth.login');
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($request, $service) {
                $service->update($request->only(['store_id', 'name', 'description', 'amount', 'currency_id', 'status']));

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
                    'user_id' => $user->id,
                    'action' => 'service_updated',
                    'description' => 'Service updated',
                    'metadata' => ['user_id' => $user->id, 'service_id' => $service->id],
                ]);
            });

            return redirect()->route('management.services.index', ['user' => $user, 'store_id' => $service->store->store_id])->with('success', 'Service updated.');
        } catch (\Throwable $e) {
            Log::error('service.update_failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Unable to update service.')->withInput();
        }
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsService($service, $user)) {
            return redirect()->route('management.auth.login');
        }

        foreach ($service->images as $img) {
            try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
        }
        $service->delete();
        return redirect()->route('management.services.index', ['user' => $user])->with('success', 'Service deleted.');
    }

    private function ownsService(Service $service, User $user): bool
    {
        return in_array((int)$service->store_id, $this->userStoreIds($user), true);
    }
}
