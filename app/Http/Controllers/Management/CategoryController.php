<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{


    private function businessStoreIds(User $user): array
    {
        return $user->stores()->pluck('id')->all();
    }

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $storeIds = $this->businessStoreIds($user);
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

        $query = Category::with('store')->withCount('products')
            ->whereIn('store_id', $storeIds);

        if ($selectedStoreId) {
            $query->where('store_id', $selectedStoreId);
        }

        $categories = $query->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('management.categories.index', [
            'user' => $user,
            'categories' => $categories,
            'stores' => $user->stores()->orderBy('name')->get(),
            'selectedStore' => $selectedStore,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $stores = $user->stores()->orderBy('name')->get();
        $selectedPublicStoreId = $request->query('store_id');
        $selectedStoreId = null;

        if ($selectedPublicStoreId) {
            $selectedStoreId = $user->stores()
                ->where('store_id', $selectedPublicStoreId)
                ->value('id');
        }

        return view('management.categories.create', [
            'user' => $user,
            'stores' => $stores,
            'selectedStoreId' => $selectedStoreId,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $storeIds = $this->businessStoreIds($user);
        if (!in_array((int)$data['store_id'], $storeIds, true)) {
            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Invalid store selection.'], 422);
            }
            return back()->with('error', 'Invalid store selection.')->withInput();
        }

        $store = Store::find($data['store_id']);
        $data['slug'] = Str::slug($data['name']) . '-' . substr((string)Str::uuid(), 0, 6);

        $category = Category::create($data);

        Log::info('category.created', ['user_id' => $user->id, 'category_id' => $category->id]);

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Category created successfully.',
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'status' => $category->status,
                ]
            ], 201);
        }

        return redirect()->route('management.categories.index', ['user' => $user, 'store_id' => $store->store_id])->with('success', 'Category created.');
    }

    public function edit(Request $request, Category $category): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsCategory($category, $user)) {
            return redirect()->route('management.auth.login');
        }

        $stores = $user->stores()->orderBy('name')->get();

        return view('management.categories.edit', [
            'user' => $user,
            'category' => $category,
            'stores' => $stores,
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsCategory($category, $user)) {
            return redirect()->route('management.auth.login');
        }

        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $storeIds = $this->businessStoreIds($user);
        if (!in_array((int)$data['store_id'], $storeIds, true)) {
            return back()->with('error', 'Invalid store selection.')->withInput();
        }

        if ($category->name !== $data['name']) {
            $data['slug'] = Str::slug($data['name']) . '-' . substr((string)Str::uuid(), 0, 6);
        }

        $category->update($data);

        Log::info('category.updated', ['user_id' => $user->id, 'category_id' => $category->id]);

        return redirect()->route('management.categories.index', ['user' => $user, 'store_id' => $category->store->store_id])->with('success', 'Category updated.');
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsCategory($category, $user)) {
            return redirect()->route('management.auth.login');
        }

        $category->delete();

        Log::info('category.deleted', ['user_id' => $user->id, 'category_id' => $category->id]);

        return redirect()->route('management.categories.index', ['user' => $user])->with('success', 'Category deleted.');
    }

    private function ownsCategory(Category $category, User $user): bool
    {
        return in_array((int)$category->store_id, $this->businessStoreIds($user), true);
    }
}
