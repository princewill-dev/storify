@extends('home.layout')
@section('title', 'Our Stores')

@section('content')

<!-- HERO -->
<!-- <section id="hero-5" class="bg--scroll hero-section division" style="background-image: url({{ asset('home/images/hero-5.jpg') }});">
    <div class="container">
        <div class="row d-flex align-items-center">
            <div class="col-md-10 col-lg-8 offset-md-1 offset-lg-2">
                <div class="hero-5-txt text-center white-color">
                    <h2 class="h2-xl">Discover Our Stores</h2>
                    <p class="p-xl">Browse through our collection of verified vendors and find the perfect store for your needs</p>
                </div>
            </div>
        </div>
    </div>
</section> -->

<br>
<br>
<br>
<br>
<br>
<br>

<!-- STORES GRID -->
<section id="stores-1" class="wide-60 stores-section division">
    <div class="container">
        
        <!-- SECTION TITLE -->
        <div class="row justify-content-center">
            <div class="col-lg-10 col-xl-8">
                <div class="section-title title-01 mb-70">
                    <!-- <h2 class="h2-md">All Stores ({{ $stores->count() }})</h2> -->
                     <h2 class="h2-md">All Stores</h2>
                    <p class="p-xl">Explore our marketplace of trusted stores</p>
                </div>
            </div>
        </div>

        <!-- STORES GRID -->
        <div class="row">
            @forelse($stores as $store)
            <div class="col-md-6 col-lg-4 col-xl-3">
                <a href="{{ store_url($store->slug) }}" class="store-card" target="_blank">
                    <div class="store-1 r-12 mb-40">
                        <!-- Store Logo -->
                        <div class="store-logo">
                            @if($store->logo_path)
                                <img class="img-fluid" src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }}">
                            @else
                                <div class="store-placeholder">
                                    <span>{{ substr($store->name, 0, 1) }}</span>
                                </div>
                            @endif
                        </div>

                        <!-- Store Info -->
                        <div class="store-txt">
                            <h5 class="h5-sm">{{ $store->name }}</h5>
                            @if($store->description)
                                <p class="grey-color">{{ Str::limit($store->description, 80) }}</p>
                            @endif
                            
                            @if($store->businessType)
                                <div class="store-meta">
                                    <span class="badge badge--tra-green">{{ $store->businessType->name }}</span>
                                </div>
                            @endif
                            
                            <div class="store-link mt-15">
                                <span class="btn-text">Visit Store <i class="fas fa-arrow-right"></i></span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            @empty
            <div class="col-12">
                <div class="text-center py-5">
                    <img src="{{ asset('home/images/empty-state.svg') }}" alt="No stores" style="max-width: 300px; opacity: 0.5;">
                    <h4 class="mt-4">No stores available yet</h4>
                    <p class="grey-color">Check back soon for new stores!</p>
                </div>
            </div>
            @endforelse
        </div>

    </div>
</section>

@endsection

@push('styles')
<style>
.store-card {
    text-decoration: none;
    display: block;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.store-card:hover {
    transform: translateY(-5px);
}

.store-1 {
    background: #fff;
    padding: 25px;
    border: 1px solid #ddd;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    height: 100%;
}

.store-card:hover .store-1 {
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    border-color: #ff4b09;
}

.store-logo {
    width: 100%;
    height: 150px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
}

.store-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 15px;
}

.store-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.store-placeholder span {
    font-size: 3.5rem;
    font-weight: 700;
    color: #fff;
}

.store-txt h5 {
    margin-bottom: 10px;
    color: #222;
    font-size: 18px;
}

.store-txt p {
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 15px;
    min-height: 45px;
    color: #666;
}

.store-meta {
    margin-bottom: 10px;
}

.store-link {
    color: #ff4b09;
    font-weight: 600;
    font-size: 14px;
}

.store-card:hover .store-link {
    color: #d63e06;
}

.store-link i {
    transition: transform 0.3s ease;
    margin-left: 5px;
}

.store-card:hover .store-link i {
    transform: translateX(5px);
}

.badge--tra-green {
    background: rgba(46, 204, 113, 0.1);
    color: #2ecc71;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}
</style>
@endpush
