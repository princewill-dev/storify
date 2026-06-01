@extends('management.layout')
@section('subtitle', 'Edit Customer')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <a href="{{ route('admin.customers.show', $customer) }}" class="btn btn-sm btn-secondary">
                    <i class="fi fi-rr-arrow-left"></i> Back to Customer
                </a>
                <h2 class="mt-3 mb-0">Edit Customer</h2>
                <p class="text-muted mb-0">Account ID: <code>{{ $customer->account_id }}</code></p>
            </div>
            <div class="text-end">
                <span class="badge bg-light text-dark border">Last login: {{ $customer->last_login?->format('M d, Y H:i') ?? 'Never' }}</span>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0">Customer Details</h4>
                </div>
                <form method="POST" action="{{ route('admin.customers.update', $customer) }}">
                    @csrf
                    @method('PUT')
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name', $customer->first_name) }}" required>
                                @error('first_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name', $customer->last_name) }}" required>
                                @error('last_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $customer->email) }}" required>
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $customer->phone) }}">
                                @error('phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-control @error('location') is-invalid @enderror" value="{{ old('location', $customer->location) }}" placeholder="City, Country">
                                @error('location')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                    @foreach($statuses as $value => $label)
                                        <option value="{{ $value }}" @selected(old('status', $customer->status) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <div class="text-muted small">
                            Created: {{ $customer->created_at->format('M d, Y H:i') }}
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('admin.customers.show', $customer) }}" class="btn btn-light">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fi fi-rr-disk"></i> Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0">Account Summary</h4>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0 small">
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Orders</span>
                            <strong>{{ $customer->orders()->count() }}</strong>
                        </li>
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Verified Email</span>
                            <span class="badge {{ $customer->hasVerifiedEmail() ? 'bg-success' : 'bg-secondary' }}">
                                {{ $customer->hasVerifiedEmail() ? 'Yes' : 'No' }}
                            </span>
                        </li>
                        <li class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Last Updated</span>
                            <span>{{ $customer->updated_at->format('M d, Y H:i') }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
