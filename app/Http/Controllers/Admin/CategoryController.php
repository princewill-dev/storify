<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request, ?Store $store = null)
    {
        Log::info('categories_viewed', ['user_id' => auth()->id()]);
        $query = Category::with(['store','parent']);
        if ($store) {
            $query->where('store_id', $store->id);
        }
        $categories = $query->orderBy('store_id')->orderBy('name')->paginate(20)->withQueryString();
        $stores = Store::orderBy('name')->get();
        return view('admin.categories.index', [
            'categories' => $categories,
            'store' => $store,
            'stores' => $stores,
        ]);
    }

    public function create(Request $request, ?Store $store = null)
    {
        Log::info('category_create_viewed', ['user_id' => auth()->id()]);
        $stores = Store::orderBy('name')->get();
        $selectedStoreId = $store?->id;
        return view('admin.categories.create', compact('stores','selectedStoreId'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);
        $data['slug'] = Str::slug($data['name']).'-'.substr((string) Str::uuid(),0,6);
        $category = Category::create($data);
        Log::info('category_created', ['user_id' => auth()->id(), 'category_id' => $category->id]);
        // Always return to the store overview page after creating a category
        return redirect()->route('admin.stores.show', $category->store)->with('success', 'Category created');
    }

    public function edit(Category $category)
    {
        Log::info('category_edit_viewed', ['user_id' => auth()->id(), 'category_id' => $category->id]);
        $stores = Store::orderBy('name')->get();
        return view('admin.categories.edit', compact('category','stores'));
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);
        // keep slug stable unless name changed drastically; regenerate if user changed name
        if ($category->name !== $data['name']) {
            $data['slug'] = Str::slug($data['name']).'-'.substr((string) Str::uuid(),0,6);
        }
        $category->update($data);
        Log::info('category_updated', ['user_id' => auth()->id(), 'category_id' => $category->id]);
        return redirect()->route('admin.stores.categories.index', $category->store)->with('success', 'Category updated');
    }

    public function destroy(Category $category)
    {
        Log::info('category_delete_requested', ['user_id' => auth()->id(), 'category_id' => $category->id]);
        $category->delete();
        return redirect()->route('admin.stores.categories.index', $category->store)->with('success', 'Category deleted');
    }
}
