<div class="icnav">
    <div class="icnav-scroll">
        <ul class="metismenu" id="menu">
            <li class="menu-title" data-i18n="Navigation">Navigation</li>

            <li>
                <a href="{{ route('vendor.dashboard') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-home"></i>
                    </div>	
                    <span class="nav-text" data-i18n="Dashboard">Dashboard</span>
                </a>
            </li>

            @php
                $sidebarVendor = auth('vendor')->user();
                $sidebarStores = $sidebarVendor->stores;
                $sidebarStoreCount = $sidebarStores->count();
            @endphp

            @if($sidebarStoreCount > 1)
                <li>
                    <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fi fi-rr-shop"></i>
                        </div>	
                        <span class="nav-text" data-i18n="My Stores">My Stores</span>
                    </a>
                    <ul aria-expanded="false">
                        @foreach($sidebarStores as $sS)
                            <li>
                                <a href="{{ route('vendor.stores.show', ['vendor' => $sidebarVendor, 'store' => $sS->store_id]) }}" data-i18n="{{ $sS->name }}">
                                    {{ $sS->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @elseif($sidebarStoreCount === 1)
                @php($onlyStore = $sidebarStores->first())
                <li>
                    <a href="{{ route('vendor.stores.show', ['vendor' => $sidebarVendor, 'store' => $onlyStore->store_id]) }}" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fi fi-rr-shop"></i>
                        </div>
                        <span class="nav-text">My Store</span>
                    </a>
                </li>
            @else
                <li>
                    <a href="{{ route('vendor.stores.index') }}" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fi fi-rr-shop"></i>
                        </div>
                        <span class="nav-text">My Store</span>
                    </a>
                </li>
            @endif

            @if($sidebarStoreCount > 1)
                <li>
                    <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fa fa-box-open"></i>
                        </div>
                        <span class="nav-text">Products</span>
                    </a>
                    <ul aria-expanded="false">
                        @foreach($sidebarStores as $sS)
                            <li>
                                <a href="{{ route('vendor.products.index', ['vendor' => $sidebarVendor, 'store_id' => $sS->store_id]) }}">
                                    {{ $sS->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @else
                <li>
                    <a href="{{ route('vendor.products.index', ['vendor' => $sidebarVendor]) }}" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fa fa-box-open"></i>
                        </div>
                        <span class="nav-text">Products</span>
                    </a>
                </li>
            @endif

            @if($sidebarStoreCount > 1)
                <li>
                    <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fa-solid fa-hand-holding-heart"></i>
                        </div>
                        <span class="nav-text">Services</span>
                    </a>
                    <ul aria-expanded="false">
                        @foreach($sidebarStores as $sS)
                            <li>
                                <a href="{{ route('vendor.services.index', ['vendor' => $sidebarVendor, 'store_id' => $sS->store_id]) }}">
                                    {{ $sS->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @else
                <li>
                    <a href="{{ route('vendor.services.index', ['vendor' => $sidebarVendor]) }}" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fa-solid fa-hand-holding-heart"></i>
                        </div>
                        <span class="nav-text">Services</span>
                    </a>
                </li>
            @endif

            @if($sidebarStoreCount > 1)
                <li>
                    <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fa-solid fa-list"></i>
                        </div>
                        <span class="nav-text">Product categories</span>
                    </a>
                    <ul aria-expanded="false">
                        @foreach($sidebarStores as $sS)
                            <li>
                                <a href="{{ route('vendor.categories.index', ['vendor' => $sidebarVendor, 'store_id' => $sS->store_id]) }}">
                                    {{ $sS->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @else
                <li>
                    <a href="{{ route('vendor.categories.index', ['vendor' => $sidebarVendor]) }}" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fa-solid fa-list"></i>
                        </div>
                        <span class="nav-text">Product categories</span>
                    </a>
                </li>
            @endif

            <li>
                <a href="{{ route('vendor.customers.index', ['vendor' => $sidebarVendor]) }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-users-alt"></i>
                    </div>
                    <span class="nav-text">Customers</span>
                </a>
            </li>

            @if($sidebarStoreCount > 1)
                <li>
                    <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fi fi-rr-box"></i>
                        </div>
                        <span class="nav-text">Orders</span>
                    </a>
                    <ul aria-expanded="false">
                        @foreach($sidebarStores as $sS)
                            <li>
                                <a href="{{ route('vendor.orders.index', ['vendor' => $sidebarVendor, 'store_id' => $sS->store_id]) }}">
                                    {{ $sS->name }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @else
                <li>
                    <a href="{{ route('vendor.orders.index', ['vendor' => $sidebarVendor]) }}" aria-expanded="false">
                        <div class="menu-icon">
                            <i class="fi fi-rr-box"></i>
                        </div>
                        <span class="nav-text">Orders</span>
                    </a>
                </li>
            @endif

            <li>
                <a href="{{ route('vendor.transactions.index', ['vendor' => $vendor]) }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <span class="nav-text">Transactions</span>
                </a>
            </li>

            <li>
                <a href="{{ route('vendor.support-messages.index', ['vendor' => $vendor]) }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa-solid fa-headset"></i>
                    </div>
                    <span class="nav-text">Support Messages</span>
                </a>
            </li>

            <!-- logout -->
            <li>
                <a href="#" aria-expanded="false" onclick="event.preventDefault(); document.getElementById('vendor-logout-form').submit();">
                    <div class="menu-icon">
                        <i class="fi fi-rr-exit"></i>
                    </div>
                    <span class="nav-text">Logout</span>
                </a>
                <form id="vendor-logout-form" action="{{ route('vendor.auth.logout') }}" method="POST" class="d-none">
                    @csrf
                </form>
            </li>

                       
        </ul>
    </div>

</div>