@extends('home.layout')
@section('title', 'Account Information')

@section('content')

<br><br><br><br>

<div class="page-content">
    <section class="content-inner-1">
        <div class="container">
            <div class="row">
                <!-- Sidebar -->
                <div class="col-lg-3 col-md-4 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="text-center mb-4">
                                <div class="avatar avatar-xl bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 32px;">
                                    {{ strtoupper(substr($customer->first_name, 0, 1)) }}
                                </div>
                                <h5 class="mt-3 mb-1">{{ $customer->first_name }} {{ $customer->last_name }}</h5>
                                <p class="text-muted small">{{ $customer->email }}</p>
                            </div>
                            <nav class="nav flex-column">
                                <a class="nav-link" href="{{ route('account.dashboard') }}">
                                    <i class="fas fa-home me-2"></i> Dashboard
                                </a>
                                <a class="nav-link active" href="{{ route('account.info') }}">
                                    <i class="fas fa-user me-2"></i> Account Info
                                </a>
                                <a class="nav-link" href="{{ route('account.orders') }}">
                                    <i class="fas fa-shopping-bag me-2"></i> My Orders
                                </a>
                                <a class="nav-link" href="{{ route('account.transactions') }}">
                                    <i class="fas fa-credit-card me-2"></i> Transactions
                                </a>
                                <form method="POST" action="{{ route('account.logout') }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="nav-link border-0 bg-transparent text-danger w-100 text-start">
                                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                                    </button>
                                </form>
                            </nav>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="col-lg-9 col-md-8">
                    <h2 class="mb-4">Account Information</h2>

                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            @foreach($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('account.info') }}">
                        @csrf
                        
                        <!-- Personal Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Personal Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="name" class="form-control" value="{{ old('name', $customer->first_name . ' ' . $customer->last_name) }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" class="form-control" value="{{ $customer->email }}" disabled>
                                        <small class="text-muted">Email cannot be changed</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">First Name</label>
                                        <input type="text" name="first_name" class="form-control" value="{{ old('first_name', $customer->first_name ?? '') }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" name="last_name" class="form-control" value="{{ old('last_name', $customer->last_name ?? '') }}" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" name="phone" class="form-control" value="{{ old('phone', $customer->phone) }}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Company Name (Optional)</label>
                                        <input type="text" name="company_name" class="form-control" value="{{ old('company_name', $customer->company_name ?? '') }}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Address Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Street Address</label>
                                        <input type="text" name="street_address" class="form-control" value="{{ old('street_address', $customer->street_address ?? '') }}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Apartment/Suite (Optional)</label>
                                        <input type="text" name="apartment" class="form-control" value="{{ old('apartment', $customer->apartment ?? '') }}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">City</label>
                                        <input type="text" name="city" class="form-control" value="{{ old('city', $customer->city ?? '') }}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">State</label>
                                        <input type="text" name="state" class="form-control" value="{{ old('state', $customer->state ?? '') }}">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">ZIP/Postal Code</label>
                                        <input type="text" name="zip_code" class="form-control" value="{{ old('zip_code', $customer->zip_code ?? '') }}">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label">Country</label>
                                        <input type="text" name="country" class="form-control" value="{{ old('country', $customer->country ?? '') }}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save me-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
