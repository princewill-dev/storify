@extends('vendors.layout')
@section('subtitle', 'Profile')

@section('content')
<div class="row">
    <div class="col-xl-3 col-lg-4">
        <div class="clearfix">
            <div class="card card-bx profile-card author-profile m-b30">
                <div class="card-body">
                    <div class="p-5">
                        <div class="author-profile">
                            <div class="author-media">
                                <img src="{{ asset('vendor_files/assets/images/usericon.png') }}" alt="">
                            </div>
                            <div class="author-info">
                                <h6 class="title">{{ $vendor->name }}</h6>
                                <span>{{ $vendor->email }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-9 col-lg-8">
        <div class="card profile-card card-bx m-b30">
            <div class="card-header">
                <h6 class="title">Account Setup</h6>
            </div>
            <div class="card-body">
                <form class="profile-form">
                    <div class="row">
                        <div class="col-sm-6 m-b30">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" value="{{ $vendor->name }}" readonly disabled>
                        </div>
                        <div class="col-sm-6 m-b30">
                            <label class="form-label">Email address</label>
                            <input type="text" class="form-control" value="{{ $vendor->email }}" readonly disabled>
                        </div>
                        <div class="col-sm-6 m-b30">
                            <label class="form-label">Phone Number</label>
                            <input type="text" class="form-control" value="{{ $vendor->phone ?? '-' }}" readonly disabled>
                        </div>
                        <div class="col-sm-6 m-b30">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" value="{{ $vendor->location ?? '-' }}" readonly disabled>
                        </div>
                        <div class="col-sm-6 m-b30">
                            <label class="form-label">Account ID</label>
                            <input type="text" class="form-control" value="{{ $vendor->account_id }}" readonly disabled>
                        </div>
                        <div class="col-sm-6 m-b30">
                            <label class="form-label">Status</label>
                            <input type="text" class="form-control" value="{{ ucfirst($vendor->status) }}" readonly disabled>
                        </div>
                         <!-- <div class="col-sm-6 m-b30">
                            <label class="form-label">Ownership Type</label>
                            <input type="text" class="form-control" value="{{ $vendor->ownershipType?->name ?? '-' }}" readonly disabled>
                        </div>
                         <div class="col-sm-6 m-b30">
                            <label class="form-label">Business Type</label>
                            <input type="text" class="form-control" value="{{ $vendor->businessType?->name ?? '-' }}" readonly disabled>
                        </div> -->
                    </div>
                </form>
            </div>
            <div class="card-footer">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    Change Password
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="{{ route('vendor.profile.password') }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control @error('current_password') is-invalid @enderror" id="current_password" name="current_password" required>
                        @error('current_password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@if($errors->hasAny(['current_password', 'password']))
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var myModal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            myModal.show();
        });
    </script>
    @endpush
@endif
@endsection
