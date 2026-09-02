<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // The cart and superadmin AJAX route files are intentionally loaded by
    // routes/web.php because they rely on sessions and CSRF protection.
    // Keep the stateless API surface explicit so those files are not mounted
    // a second time under /api/v1/api/*.
    require __DIR__.'/api/v1/pos.php';
});
