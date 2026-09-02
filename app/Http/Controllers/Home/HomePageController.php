<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\SubscriptionPlan;
use App\Models\Testimonial;

class HomePageController extends Controller
{
    /**
     * Display the welcome page.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        $stores = Store::where('status', 'active')
            ->where('has_website', true)
            ->with('user')
            ->latest()
            ->take(6)
            ->get();

        $testimonials = Testimonial::where('status', 'active')
            ->latest()
            ->take(6)
            ->get();

        $storeCount = Store::where('status', 'active')->count();
        $productCount = Product::where('status', 'active')->count();
        $plans = SubscriptionPlan::active()->where('is_trial', false)->orderBy('sort_order')->get();

        return view('home.pages.index', compact(
            'stores', 'testimonials', 'storeCount', 'productCount', 'plans'
        ));
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
        $stores = Store::where('status', 'active')
            ->with('user')
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
        $plans = SubscriptionPlan::active()
            ->where('is_trial', false)
            ->orderBy('sort_order')
            ->get();

        return view('home.pages.pricing', compact('plans'));
    }
}
