<div class="icnav">
    <div class="icnav-scroll">
        <ul class="metismenu" id="menu">
            <li class="menu-title" data-i18n="Navigation">Navigation</li>

            <li>
                <a href="{{ route('admin.dashboard') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-home"></i>
                    </div>	
                    <span class="nav-text" data-i18n="Dashboard">Dashboard</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.executive') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-chart-histogram"></i>
                    </div>	
                    <span class="nav-text">Executive</span>
                </a>
            </li>

            <!-- @if(!empty($adminMainStore))
            <li>
                <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-store-alt"></i>
                    </div>	
                    <span class="nav-text" data-i18n="Main Store">Main Store</span>
                </a>
                <ul aria-expanded="false">
                    <li><a href="{{ route('admin.stores.show', $adminMainStore) }}" data-i18n="Details">Details</a></li>
                    <li><a href="{{ route('admin.storefront-slides.index', $adminMainStore) }}" data-i18n="Edit slides">Edit slides</a></li>
                    <li><a href="{{ route('admin.stores.products.index', $adminMainStore) }}" data-i18n="Manage Products">Manage Products</a></li>
                    <li><a href="{{ route('admin.stores.categories.index', $adminMainStore) }}" data-i18n="Manage Categories">Manage Categories</a></li>
                </ul>
            </li>
            @endif -->

            <li>
                <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-building"></i>
                    </div>
                    <span class="nav-text" data-i18n="Businesses">Businesses</span>
                </a>
                <ul aria-expanded="false">
                    <li><a href="{{ route('admin.vendors.index') }}" data-i18n="All">All Businesses</a></li>
                    <li><a href="{{ route('admin.vendor-kyc.index') }}" data-i18n="KYC submissions">KYC submissions</a></li>
                    <li><a href="{{ route('admin.early-access.index') }}" data-i18n="Access code">Access code</a></li>
                </ul>
            </li>

            <li>
                <a href="{{ route('admin.stores.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-store-alt"></i>
                    </div>
                    <span class="nav-text">Stores</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.warehouses.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-warehouse-alt"></i>
                    </div>
                    <span class="nav-text">Warehouses</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.transfers.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-arrows-exchange"></i>
                    </div>
                    <span class="nav-text">Stock Transfers</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.subscriptions.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-credit-card"></i>
                    </div>
                    <span class="nav-text">Subscriptions</span>
                </a>
            </li>

            <li>
                <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fas fa-users"></i>
                    </div>	
                    <span class="nav-text" data-i18n="Customers">Customers</span>
                </a>
                <ul aria-expanded="false">
                    <li><a href="{{ route('admin.customers.index') }}" data-i18n="Customers">Customers</a></li>
                </ul>
            </li>

            <!-- <li>
                <a href="{{ route('admin.live-first.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-badge-check"></i>
                    </div>
                    <span class="nav-text">Live First Enrolments</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.bulk-orders.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-box"></i>
                    </div>
                    <span class="nav-text">Bulk-Buy Requests</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.family-packs.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-box"></i>
                    </div>
                    <span class="nav-text">Family Packs Requests</span>
                </a>
            </li> -->

            <!-- <li>
                <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-box"></i>
                    </div>	
                    <span class="nav-text" data-i18n="Orders">Orders</span>
                </a>
                <ul aria-expanded="false">
                    <li><a href="{{ route('admin.orders.index') }}" data-i18n="All">All</a></li>
                    <li><a href="{{ route('admin.shop4me.orders.index') }}" data-i18n="Shop4me">Shop4me</a></li>
                    <li><a href="{{ route('admin.bulkbuy.orders.index') }}" data-i18n="Bulk buy">Bulk buy</a></li>
                    <li><a href="{{ route('admin.familypack.orders.index') }}" data-i18n="Family Packs">Family Packs</a></li>
                    <li><a href="{{ route('admin.livefirst.orders.index') }}" data-i18n="Live First">Live First</a></li>
                </ul>
            </li> -->

            <li>
                <a href="{{ route('admin.transactions.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <span class="nav-text">Transactions</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.testimonials.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa-solid fa-comment-dots"></i>
                    </div>
                    <span class="nav-text">Testimonials</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.support-messages.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa-solid fa-headset"></i>
                    </div>
                    <span class="nav-text">Support Messages</span>
                </a>
            </li>
            
            <li class="menu-title" data-i18n="Settings">Settings</li>

            <li>
                <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-box"></i>
                    </div>	
                    <span class="nav-text" data-i18n="Homepage settings">Homepage settings</span>
                </a>
                <ul aria-expanded="false">
                    <li><a href="{{ route('admin.features.index') }}" data-i18n="Features">Features</a></li>
                </ul>
            </li>

            <li>
                <a href="{{ route('admin.payment-methods.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fa-solid fa-credit-card"></i>
                    </div>
                    <span class="nav-text">Payment Methods</span>
                </a>
            </li>

            <li>
                <a class="has-arrow" href="javascript:void(0);" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-box"></i>
                    </div>	
                    <span class="nav-text" data-i18n="Business settings">Business settings</span>
                </a>
                <ul aria-expanded="false">
                    <li><a href="{{ route('admin.business-types.index') }}" data-i18n="Business types">Business types</a></li>
                    <li><a href="{{ route('admin.ownership-types.index') }}" data-i18n="Ownership types">Ownership types</a></li>
                    <li><a href="{{ route('admin.vats.index') }}" data-i18n="VAT Settings">VAT Settings</a></li>
                    <li><a href="{{ route('admin.bank-accounts.index') }}" data-i18n="Bank Accounts">Bank Accounts</a></li>
                    <li><a href="{{ route('admin.subscription-plans.index') }}" data-i18n="Subscriptions Fee">Subscriptions Fee</a></li>
                </ul>
            </li>

            <li>
                <a href="{{ route('admin.company-services.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-bulb"></i>
                    </div>
                    <span class="nav-text">Company Services</span>
                </a>
            </li>

            <!-- <li>
                <a href="{{ route('admin.vats.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-percentage"></i>
                    </div>
                    <span class="nav-text">VAT Settings</span>
                </a>
            </li> -->

            <li>
                <a href="{{ route('admin.delivery-routes.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-route"></i>
                    </div>
                    <span class="nav-text">Delivery Routes</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.delivery-intervals.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-time-fast"></i>
                    </div>
                    <span class="nav-text">Delivery Intervals</span>
                </a>
            </li>

            <!-- <li>
                <a href="{{ route('admin.business-types.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-briefcase"></i>
                    </div>
                    <span class="nav-text">Business Types</span>
                </a>
            </li> -->

            <!-- <li>
                <a href="{{ route('admin.ownership-types.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-id-badge"></i>
                    </div>
                    <span class="nav-text">Ownership Types</span>
                </a>
            </li> -->

            <!-- Styling -->
            <li>
                <a href="{{ route('admin.styling.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-palette"></i>
                    </div>
                    <span class="nav-text">Page Styling</span>
                </a>
            </li>

            <li>
                <a href="{{ route('admin.settings.edit') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rs-settings"></i>
                    </div>
                    <span class="nav-text" data-i18n="Advanced">Advanced</span>
                </a>
            </li>

            <!-- activity logs -->
            <li>
                <a href="{{ route('admin.activity-logs.index') }}" aria-expanded="false">
                    <div class="menu-icon">
                        <i class="fi fi-rr-bulb"></i>
                    </div>
                    <span class="nav-text">Activity Logs</span>
                </a>
            </li>

            <!-- logout -->
            <li>
                <a href="#" aria-expanded="false" onclick="event.preventDefault(); document.getElementById('admin-logout-form').submit();">
                    <div class="menu-icon">
                        <i class="fi fi-rr-exit"></i>
                    </div>
                    <span class="nav-text">Logout</span>
                </a>
                <form id="admin-logout-form" action="{{ route('admin.logout') }}" method="POST" class="d-none">
                    @csrf
                </form>
            </li>

                       
        </ul>
    </div>
    <!-- <div class="icnav-footer">
        <a href="https://coreui.w3itexperts.com/?theme=HexaBox" target="_blank" class="btn btn-docs btn-success w-100">
            <span>Docs & Components</span>
            <i class="fa-solid fa-arrow-up rotate-x"></i>
        </a>
    </div> -->
</div>