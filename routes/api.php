<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    foreach (glob(__DIR__ . '/api/v1/*.php') as $file) {
        require $file;
    }
});
