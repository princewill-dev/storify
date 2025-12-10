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

            <li>
                <a href="{{ route('vendor.stores.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-store-alt"></i>
                    </div>
                    <span class="nav-text">My Store</span>
                </a>
            </li>

            <li>
                <a href="{{ route('vendor.products.index', ['vendor' => auth('vendor')->user()]) }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa fa-box-open"></i>
                    </div>
                    <span class="nav-text">Products</span>
                </a>
            </li>

            <li>
                <a href="{{ route('vendor.categories.index', ['vendor' => auth('vendor')->user()]) }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa-solid fa-list"></i>
                    </div>
                    <span class="nav-text">Product categories</span>
                </a>
            </li>

            <li>
                <a href="{{ route('vendor.customers.index', ['vendor' => auth('vendor')->user()]) }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-users-alt"></i>
                    </div>
                    <span class="nav-text">Customers</span>
                </a>
            </li>

            <li>
                <a href="{{ route('vendor.orders.index', ['vendor' => auth('vendor')->user()]) }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-box"></i>
                    </div>
                    <span class="nav-text">Orders</span>
                </a>
            </li>

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