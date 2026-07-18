<div class="sidebar__area">
    <div class="sidebar__wrapper">
    <div class="sidebar__close">
        <button class="sidebar__close-btn" id="sidebar__close-btn">
        <span><i class="fal fa-times"></i></span>
        <span>close</span>
        </button>
    </div>
    <div class="sidebar__content">
        <div class="logo mb-40">
            <a href="{{ store_url($store->slug) }}">
                @if($store->logo_path)
                    <img src="{{ asset('storage/' . $store->logo_path) }}" alt="{{ $store->name }}" style="max-height: 50px;">
                @else
                    <span style="font-size:20px;font-weight:700;color:#fff;">{{ $store->name ?? 'Store' }}</span>
                @endif
            </a>
        </div>
        <div class="mobile-menu"></div>
        <!-- <div class="sidebar__action mt-330">
            <div class="sidebar__login mt-15">
                <a href="#"><i class="far fa-unlock"></i> Log In</a>
            </div>
            <div class="sidebar__cart mt-20">
                <a href="javascript:void(0);" class="cart-toggle-btn">
                <i class="far fa-shopping-cart"></i>
                <span>2</span>
                </a>
            </div>
        </div> -->
    </div>
    </div>
</div>