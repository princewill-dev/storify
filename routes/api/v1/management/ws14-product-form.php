<?php

use App\Http\Controllers\Api\V1\Management\ProductFormController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WS-14 — Products Form, Variants & Media
|--------------------------------------------------------------------------
| Registered inside the management group (prefix "management", name
| "api.management.", auth/audience/team middleware), so only the Route:: lines
| belong here.
|
| index/show/store/update/status/destroy deliberately reuse the URIs declared
| further up routes/api/v1/management.php: Laravel keys routes by method+URI,
| so these registrations replace the thin ProductController versions with the
| full form implementation. The form-options and per-image routes are new.
|
| `GET products` is itself superseded by WS-25's module, which loads after this
| one (module files load alphabetically) and serves the list with a superset
| payload — verified via route:list. It stays registered here so the earlier
| widened list remains the fallback if that module is ever absent.
|
| Note the three-segment `products/form/options` URI — it keeps clear of the
| `products/{product}` binding, which resolves its route key (product_code).
*/

Route::middleware('permission:products view')->group(function () {
    Route::get('products', [ProductFormController::class, 'index'])->name('products.index');
    Route::get('products/form/options', [ProductFormController::class, 'formOptions'])->name('products.form-options');
    Route::get('products/{product}', [ProductFormController::class, 'show'])->name('products.show');
});

Route::middleware('permission:products create')->group(function () {
    Route::post('products', [ProductFormController::class, 'store'])->name('products.store');
});

Route::middleware('permission:products edit')->group(function () {
    Route::put('products/{product}', [ProductFormController::class, 'update'])->name('products.update');
    Route::put('products/{product}/status', [ProductFormController::class, 'updateStatus'])->name('products.status');
    Route::put('products/{product}/images/{image}/primary', [ProductFormController::class, 'setPrimaryImage'])
        ->name('products.images.primary');
    Route::delete('products/{product}/images/{image}', [ProductFormController::class, 'destroyImage'])
        ->name('products.images.destroy');
});

Route::middleware('permission:products delete')->group(function () {
    Route::delete('products/{product}', [ProductFormController::class, 'destroy'])->name('products.destroy');
});
