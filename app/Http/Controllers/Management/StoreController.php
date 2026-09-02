<?php

namespace App\Http\Controllers\Management;

use App\Actions\Stores\CreateStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Management\CreateStoreRequest;
use App\Models\BusinessType;
use App\Models\Currency;
use App\Models\OwnershipType;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use App\Services\StoreAccessService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class StoreController extends Controller
{
    public function __construct(
        private readonly CreateStore $createStore,
        private readonly StoreAccessService $access,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user->is_verified) {
            return redirect()->route('management.auth.verify-otp')->with('warning', 'Please verify your email to continue.');
        }

        $defaults = session('pending_store_defaults', []);
        $defaults = [
            'name' => $defaults['name'] ?? $user->name,
            'support_email' => $defaults['support_email'] ?? $user->email,
            'support_phone' => $defaults['support_phone'] ?? $user->phone,
            'address' => $defaults['address'] ?? ($user->location ?? ''),
        ];
        $ownershipTypes = OwnershipType::orderBy('name')->get(['id', 'name']);
        $businessTypes = BusinessType::orderBy('name')->get(['id', 'name']);
        $currencies = Currency::orderBy('name')->get();
        $activeStaff = User::where('business_id', $user->business_id)
            ->where('role', 'staff')->where('status', 'active')->with('roles')->get(['id', 'name']);
        $userBanks = StoreBank::where('business_id', $user->business_id)->get();
        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => 'Create'],
        ];

        return view('management.stores.create', compact(
            'user', 'ownershipTypes', 'businessTypes', 'defaults', 'activeStaff', 'userBanks', 'currencies', 'breadcrumbs'
        ));
    }

    public function store(CreateStoreRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->is_verified) {
            return redirect()->route('management.auth.verify-otp')->with('warning', 'Please verify your email to continue.');
        }

        $data = $request->validated();
        $defaults = session('pending_store_defaults', []);
        $data['support_email'] ??= $defaults['support_email'] ?? $user->email;
        $data['support_phone'] ??= $defaults['support_phone'] ?? $user->phone;
        $data['address'] ??= $defaults['address'] ?? $user->location;

        try {
            $store = $this->createStore->execute($user, $data, $request->file('logo'));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $errorReference = Str::upper(Str::random(8));
            Log::error('store.create_failed', [
                'error_ref' => $errorReference,
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'sql' => $e instanceof QueryException ? $e->getSql() : null,
            ]);

            return back()->withInput()->with('error', "We could not create your store. Please try again. (Ref: {$errorReference})");
        }

        session()->forget('pending_store_defaults');

        return redirect()->route('management.stores.show', $store)->with('success', 'Store created successfully!');
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $status = strtolower($request->string('status')->toString());
        $search = trim($request->string('q')->toString());
        $query = ($user->isStaff() ? $user->assignedStores() : $user->accessibleStores())
            ->with(['ownershipType', 'businessType'])->withCount(['products', 'categories']);

        in_array($status, ['active', 'inactive', 'suspended', 'deleted'], true)
            ? $query->where('status', $status)
            : $query->where('status', '!=', Store::STATUS_DELETED);
        if ($search !== '') {
            $query->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('store_id', 'like', "%{$search}%"));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return view('management.stores.index', [
            'stores' => $query->latest()->paginate(10)->withQueryString(),
            'status' => $request->query('status'),
            'q' => $search,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'ownershipTypes' => OwnershipType::orderBy('name')->get(['id', 'name']),
            'businessTypes' => BusinessType::orderBy('name')->get(['id', 'name']),
            'canCreate' => ! $user->isStaff(),
            'user' => $user,
            'breadcrumbs' => [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Stores']],
        ]);
    }

    public function success(Request $request, Store $store): View|RedirectResponse
    {
        if (! $this->access->allows($request->user(), $store)) {
            return redirect()->route('management.stores.index')->with('error', 'You do not have access to that store.');
        }

        $storeUrl = $store->slug ? route('home.store.products.index', ['store_subdomain' => $store->slug]) : null;
        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name, 'url' => route('management.stores.show', $store)],
            ['label' => 'Setup Complete'],
        ];

        return view('management.stores.success', compact('store', 'storeUrl', 'breadcrumbs'));
    }

    public function checkSlugAvailability(Request $request): JsonResponse
    {
        $base = Str::slug($request->string('name')->toString());
        if ($base === '') {
            return response()->json(['available' => false, 'slug' => '', 'url' => '']);
        }

        $slug = $base;
        $counter = 1;
        while (Store::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }
        $domain = config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST));

        return response()->json([
            'available' => $slug === $base,
            'slug' => $slug,
            'url' => $slug.'.'.$domain,
            'original' => $base,
        ]);
    }
}
