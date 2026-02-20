<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class HomePageController extends Controller
{
    /**
     * Display the welcome page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        return view('home.pages.index');
    }

    /**
     * Display the about us page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function about()
    {
        return view('home.pages.about');
    }

    /**
     * Display the support page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function support()
    {
        return view('home.pages.support');
    }

    /**
     * Display the stores page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function stores()
    {
        $stores = \App\Models\Store::where('status', 'active')
            ->with('vendor')
            ->latest()
            ->get();
            
        return view('home.pages.our-stores', compact('stores'));
    }

    /**
     * Display the services page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function services()
    {
        return view('home.pages.services');
    }

    /**
     * Display the pricing page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function pricing()
    {
        $plans = \App\Models\SubscriptionPlan::active()
            ->orderBy('sort_order')
            ->get();
            
        return view('home.pages.pricing', compact('plans'));
    }
}