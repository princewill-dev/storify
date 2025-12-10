<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\View\View;

class InternationalSupplyController extends Controller
{
    public function page(string $store_slug): View
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        return view('home.pages.intl_supply.coming_soon', compact('store'));
    }
}
