@extends('account.layout')
@section('title', 'Profile & Addresses')
@section('subtitle', 'Profile & Addresses')

@section('content')
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="px-4 py-3 border-bottom"><h6 class="mb-0 fw-semibold">Profile</h6></div>
            <div class="p-4">
                <form method="POST" action="{{ route('account.info') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label small fw-medium">First Name</label>
                        <input type="text" name="first_name" value="{{ old('first_name', $customer->first_name) }}" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-medium">Last Name</label>
                        <input type="text" name="last_name" value="{{ old('last_name', $customer->last_name) }}" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-medium">Email</label>
                        <input type="email" value="{{ $customer->email }}" class="form-control bg-light" disabled>
                        <small class="text-muted">Email cannot be changed</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-medium">Phone</label>
                        <input type="text" name="phone" value="{{ old('phone', $customer->phone) }}" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm px-4">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
                <h6 class="mb-0 fw-semibold">Saved Addresses</h6>
                <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addAddressForm">
                    <i class="fa-solid fa-plus me-1"></i> Add New
                </button>
            </div>
            <div class="collapse {{ $addresses->isEmpty() ? 'show' : '' }} border-bottom" id="addAddressForm">
                <div class="p-4 bg-light">
                    <form method="POST" action="{{ route('account.addresses.store') }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small">Label</label>
                                <select name="label" class="form-select form-select-sm">
                                    <option value="Home">Home</option>
                                    <option value="Work">Work</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Recipient Name</label>
                                <input type="text" name="recipient_name" value="{{ $customer->full_name }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Phone</label>
                                <input type="text" name="recipient_phone" value="{{ $customer->phone }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Delivery Route</label>
                                <select name="delivery_route_id" class="form-select form-select-sm">
                                    <option value="">Select route...</option>
                                    @foreach(\App\Models\DeliveryRoute::where('active', true)->orderBy('state')->orderBy('area')->limit(50)->get() as $route)
                                    <option value="{{ $route->id }}">{{ $route->area }}, {{ $route->state }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-8">
                                <label class="form-label small">Street Address</label>
                                <input type="text" name="street_address" class="form-control form-control-sm" placeholder="House no., street name" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label small">Apartment</label>
                                <input type="text" name="apartment" class="form-control form-control-sm" placeholder="Apt, suite, etc.">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">City</label>
                                <input type="text" name="city" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">State</label>
                                <input type="text" name="state" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">Country</label>
                                <input type="text" name="country" value="Nigeria" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label class="form-label small">ZIP Code</label>
                                <input type="text" name="zip_code" class="form-control form-control-sm">
                            </div>
                            <div class="col-12">
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_default" value="1" class="form-check-input" id="setDefault">
                                    <label class="form-check-label small" for="setDefault">Set as default address</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mt-3 px-4">Save Address</button>
                    </form>
                </div>
            </div>

            <div class="p-0">
                @forelse($addresses as $addr)
                <div class="d-flex align-items-start gap-3 p-4 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div class="rounded-circle bg-secondary bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;">
                        <i class="fa-solid {{ $addr->label === 'Work' ? 'fa-briefcase' : 'fa-house' }} text-secondary small"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="fw-semibold small">{{ $addr->label ?? 'Address' }}</span>
                            @if($addr->is_default)
                            <span class="badge bg-dark bg-opacity-10 text-dark" style="font-size:10px;">Default</span>
                            @endif
                        </div>
                        <p class="mb-1 small">{{ $addr->recipient_name }} · {{ $addr->recipient_phone }}</p>
                        <p class="mb-0 text-muted" style="font-size:12px;">{{ $addr->full_address }}</p>
                    </div>
                </div>
                @empty
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-map-location-dot fa-2x mb-3 d-block"></i>
                    <p class="small">No saved addresses yet</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
