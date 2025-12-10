<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/v1/admin_auth.php';
require __DIR__ . '/v1/vendor.php';
require __DIR__ . '/v1/checkout.php';
require __DIR__ . '/v1/shop4me.php';
require __DIR__ . '/v1/account.php';
require __DIR__ . '/v1/admin_dashboard.php';
require __DIR__ . '/v1/family_pack.php'; // MUST be before home.php to avoid route conflicts
require __DIR__ . '/v1/home.php';




// Admin API routes (AJAX)
require __DIR__ . '/api/v1/admin_storefront.php';
