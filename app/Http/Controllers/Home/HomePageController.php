<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Product;
use App\Models\StorefrontSlide;
use App\Models\CompanyService;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Models\Currency;
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
        if (Product::count() === 0) {
            return view('home.pages.management.incomplete_setup');
        }
        $settings = Setting::query()->first();
        $mainStoreId = $settings->main_store_id ?? null;
        $mainStore = null;
        if ($mainStoreId) {
            $mainStore = Store::find($mainStoreId);
        }
        if (!$mainStore) {
            $mainStore = Store::where('status', 'active')->orderBy('id')->first();
        }

        $homepageProducts = collect();
        $slides = collect();
        $fallbackProducts = collect();
        $services = CompanyService::where('status','active')->get();
        if ($mainStore) {
            $homepageProducts = Product::where('store_id', $mainStore->id)
                ->where('status', 'active')
                ->with('images')
                ->latest()
                ->take(12)
                ->get();

            $slides = StorefrontSlide::where('store_id', $mainStore->id)
                ->where('status', 'active')
                ->with(['product.images'])
                ->orderBy('position')
                ->get();

            if ($slides->isEmpty()) {
                $fallbackProducts = Product::where('store_id', $mainStore->id)
                    ->where('status','active')
                    ->with('images')
                    ->latest()
                    ->take(3)
                    ->get();
            }
        }

        // Trending products (latest products across all stores)
        $trendingProducts = Product::where('status', 'active')
            ->with(['images', 'store'])
            ->latest()
            ->take(12)
            ->get();

        // Testimonials
        $testimonials = \App\Models\Testimonial::active()
            ->orderBy('position')
            ->orderBy('created_at', 'desc')
            ->get();

        // Featured products for homepage section
        $featuredAll = Product::where('status','active')
            ->featured()
            ->with(['images','category'])
            ->latest()
            ->take(8)
            ->get();
        $featuredCategoryIds = Product::where('status','active')
            ->featured()
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id');
        $featuredCategories = Category::whereIn('id', $featuredCategoryIds)
            ->where('status','active')
            ->orderBy('name')
            ->get();
        $featuredByCategory = [];
        foreach ($featuredCategories as $fc) {
            $featuredByCategory[$fc->id] = Product::where('status','active')
                ->featured()
                ->where('category_id', $fc->id)
                ->with(['images','category'])
                ->latest()
                ->take(8)
                ->get();
        }

        // Build slide view models to avoid raw PHP in blade
        $slidesVm = [];
        $symbol = optional(Currency::where('is_default', true)->first())->symbol ?? '';
        $placeholder = asset('home/images/banner/banner-media.png');
        $slidesImages = [];
        foreach ($slides as $sl) {
            $p = $sl->product;
            $title = $sl->title ?: ($p?->name);
            $rawDesc = trim((string)($sl->description ?: ($p?->description ?? '')));
            $rawDesc = html_entity_decode($rawDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rawDesc = strip_tags($rawDesc);
            $firstSentence = '';
            if ($rawDesc !== '') {
                $parts = preg_split('/(?<=[.!?])\s+/', $rawDesc, 2);
                $firstSentence = $parts[0] ?? '';
            }
            $price = $sl->price_override ?? ($p?->amount);
            $priceFormatted = '';
            if ($price !== null && $price !== '') {
                if (is_numeric($price)) {
                    $priceFormatted = $symbol . number_format((float)$price, 2);
                } else {
                    $priceFormatted = (string) $price;
                }
            }
            $slidesVm[$sl->id] = [
                'title' => $title,
                'firstSentence' => $firstSentence,
                'price' => $priceFormatted,
            ];
            // Slide image priority: slide image -> product primary -> placeholder
            $pPrimary = $p?->primaryImage();
            $slidesImages[$sl->id] = $sl->image_path
                ? asset('storage/'.$sl->image_path)
                : ($pPrimary && $pPrimary->path ? asset('storage/'.$pPrimary->path) : $placeholder);
        }

        // Primary image URLs and formatted prices for featured products
        $featuredImages = [];
        $featuredPrices = [];
        foreach ($featuredAll as $fp) {
            $pi = $fp->primaryImage();
            $featuredImages[$fp->id] = $pi && $pi->path ? asset('storage/'.$pi->path) : asset('home/images/banner/banner-media.png');
            $featuredPrices[$fp->id] = $fp->amount !== null ? ($symbol . number_format((float)$fp->amount, 2)) : '';
        }

        // Fallback products view model (image + first sentence)
        $fallbackImages = [];
        $fallbackFirst = [];
        foreach ($fallbackProducts as $fp2) {
            $pi = $fp2->primaryImage();
            $fallbackImages[$fp2->id] = $pi && $pi->path ? asset('storage/'.$pi->path) : $placeholder;
            $raw = trim((string)($fp2->description ?? ''));
            // Decode HTML entities and strip tags for fallback products
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $raw = strip_tags($raw);
            $first = '';
            if ($raw !== '') {
                $parts = preg_split('/(?<=[.!?])\s+/', $raw, 2);
                $first = $parts[0] ?? '';
            }
            $fallbackFirst[$fp2->id] = $first;
        }

        // Services view model (background image + wow delay)
        $servicesVm = [];
        foreach ($services->values() as $i => $svc) {
            $bg = $svc->background_image_path ? asset('storage/'.$svc->background_image_path) : asset('home/images/shop/large/product'.(($i%2)+1).'.png');
            $servicesVm[$svc->id] = [
                'bg' => $bg,
                'delay' => sprintf('0.%ds', ($i%5)+1),
            ];
        }

        $company = View::shared('company');
        $brandStore = $mainStore;
        $brandLogo = $brandStore?->logo_path ? asset('storage/'.$brandStore->logo_path) : ($company->logo ?? asset('logo.png'));
        $brandUrl = $brandStore ? route('home.store.products.index', ['store_slug' => $brandStore->slug]) : route('home.index');

        return view('home.pages.index', compact(
            'mainStore',
            'homepageProducts',
            'slides',
            'fallbackProducts',
            'services',
            'trendingProducts',
            'testimonials',
            'featuredAll',
            'featuredCategories',
            'featuredByCategory',
            'slidesVm',
            'featuredImages',
            'featuredPrices',
            'slidesImages',
            'fallbackImages',
            'fallbackFirst',
            'servicesVm'
        ))->with([
            'brandStore' => $brandStore,
            'brandLogo' => $brandLogo,
            'brandUrl' => $brandUrl,
        ]);
    }
}