@extends('home.layout')
@section('title', 'Pricing Plans')

@section('content')

    <!-- PAGE HERO
    ============================================= -->
    <section id="pricing-page" class="bg--04 pb-60 inner-page-hero pricing-section">
        <div class="container">


            <!-- SECTION TITLE -->
            <div class="row justify-content-center">
                <div class="col-md-10 col-lg-8">
                    <div class="section-title text-center mb-60">
                        <h2 class="s-50 w-700">Simple, Transparent Pricing</h2>
                        <p class="s-21 color--grey">Choose the perfect plan for you. No hidden fees.</p>
                    </div>
                </div>
            </div>


            <!-- PRICING TABLES -->
            <div class="pricing-1-wrapper">
                <div class="row row-cols-1 row-cols-md-2 justify-content-center g-4">

                    @foreach($plans as $plan)
                    <!-- PRICING PLAN -->
                    <div class="col">
                        <div class="p-table pricing-1-table bg-white block-shadow r-12 wow fadeInUp" style="color: #111827 !important; {{ $plan->is_default ? 'border:2px solid #0054ff;' : '' }}">

                            <!-- TABLE HEADER -->
                            <div class="pricing-table-header">

                                <!-- Title -->
                                <h5 class="s-24 w-700" style="color: #111827 !important;">{{ $plan->name }}</h5>
                                
                                @if($plan->is_default)
                                    <span class="badge bg-primary text-white mb-2">⭐ Popular</span>
                                @endif

                                <!-- Price -->
                                <div class="price mt-15">
                                    <div class="price2">
                                        <span style="color: #111827 !important;">{{ $plan->currency }}</span>
                                        <span class="s-40 w-700" style="color: #111827 !important;">{{ number_format($plan->amount, 2) }}</span>
                                    </div>
                                    <p class="p-sm" style="color: #4b5563 !important;">{{ ucfirst($plan->interval) }}ly Billing</p>
                                </div>

                                <!-- Text -->
                                @if($plan->description)
                                    <p class="mt-20" style="color: #4b5563 !important;">{{ $plan->description }}</p>
                                @endif

                            </div>
                            <!-- END TABLE HEADER -->

                            <!-- TABLE FEATURES -->
                            <div class="pricing-table-features">
                                <ul class="simple-list">
                                    @if($plan->features && is_array($plan->features))
                                        @foreach($plan->features as $feature)
                                        <li class="list-item">
                                            <p style="color: #111827 !important;"><i class="fas fa-check color--theme me-2"></i> {{ $feature }}</p>
                                        </li>
                                        @endforeach
                                    @else
                                        <li class="list-item">
                                            <p style="color: #111827 !important;"><i class="fas fa-check color--theme me-2"></i> Standard features included</p>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                            <!-- END TABLE FEATURES -->

                            <!-- TABLE BUTTON -->
                            <div class="pricing-table-btn">
                                <a href="{{ route('management.auth.register') }}" class="btn {{ $plan->is_default ? 'btn--theme' : 'btn--tra-black hover--theme' }}">Start Free Trial</a>
                            </div>

                        </div>
                    </div>
                    <!-- END PRICING PLAN -->
                    @endforeach

                </div>
            </div>
            <!-- END PRICING TABLES -->


        </div>       <!-- End container -->
    </section>    <!-- END PRICING-1 -->


    <!-- DIVIDER LINE -->
    <hr class="divider">


@endsection
