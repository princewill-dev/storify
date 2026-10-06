<?php

use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Middleware\AdminApiActivityLogger;

/*
|--------------------------------------------------------------------------
| AD-15 — Product & category catalogue (WS15)
|--------------------------------------------------------------------------
| Loaded by routes/api/v1/admin.php inside its auth / audience / team group,
| so these inherit the "admin" prefix, the "api.admin." name and that
| middleware. Only Route:: lines belong in this file.
|
| `permission:admin.products` is the seeded gate the roadmap maps to products
| and categories (SpatiePermissionSeeder). AdminApiActivityLogger rides the
| routes so "who looked at / changed the catalogue" is audited; it de-dupes
| per request against WS-1's future group-level wiring.
|
| Bindings: `{product}` is Product's `product_code` and `{store}` is Store's
| public `store_id`; the store-scoped product show looks the code up inside
| the store on purpose (legacy `showInStore`), so a copied URL cannot read
| another store's product. `{category}` binds by id — Category has no public
| code and legacy addressed it by id too.
|
| `products/form-options` feeds the create/edit form's dropdowns; it is
| registered before the `{product}` wildcard for readability (static segments
| outscore parameters in the matcher, so the two can never collide).
*/

Route::middleware(['permission:admin.products', AdminApiActivityLogger::class])->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/form-options', [ProductController::class, 'formOptions'])->name('products.form-options');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::put('products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::get('stores/{store}/products', [ProductController::class, 'storeIndex'])->name('stores.products.index');
    Route::get('stores/{store}/products/{product}', [ProductController::class, 'showInStore'])->name('stores.products.show');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('stores/{store}/categories', [CategoryController::class, 'storeIndex'])->name('stores.categories.index');
});
