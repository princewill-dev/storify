@extends('admin.layout')
@section('subtitle', 'Create Bank Account')

@section('content')
<div class="container-fluid">
  <div class="mb-3">
    <a href="{{ route('admin.bank-accounts.index') }}" class="btn btn-outline-secondary btn-sm">← Back to Bank Accounts</a>
  </div>

  <div class="card">
    <div class="card-header">
      <h4 class="mb-0">Add New Bank Account</h4>
    </div>
    <div class="card-body">
      <form method="POST" action="{{ route('admin.bank-accounts.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="row">
          <div class="col-md-6 mb-3">
            <label for="bank_name" class="form-label">Bank Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control @error('bank_name') is-invalid @enderror" 
                   id="bank_name" name="bank_name" value="{{ old('bank_name') }}" required>
            @error('bank_name')
              <div class="invalid-feedback">{{ $message }}</div>
            @enderror
          </div>

          <div class="col-md-6 mb-3">
            <label for="account_number" class="form-label">Account Number <span class="text-danger">*</span></label>
            <input type="text" class="form-control @error('account_number') is-invalid @enderror" 
                   id="account_number" name="account_number" value="{{ old('account_number') }}" required>
            @error('account_number')
              <div class="invalid-feedback">{{ $message }}</div>
            @enderror
          </div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
            <label for="account_name" class="form-label">Account Name</label>
            <input type="text" class="form-control @error('account_name') is-invalid @enderror" 
                   id="account_name" name="account_name" value="{{ old('account_name') }}">
            @error('account_name')
              <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <small class="text-muted">e.g., Zimoziswift Limited</small>
          </div>

          <div class="col-md-3 mb-3">
            <label for="sort_order" class="form-label">Sort Order</label>
            <input type="number" class="form-control @error('sort_order') is-invalid @enderror" 
                   id="sort_order" name="sort_order" value="{{ old('sort_order', 0) }}">
            @error('sort_order')
              <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <small class="text-muted">Lower numbers appear first</small>
          </div>

          <div class="col-md-3 mb-3">
            <label for="is_active" class="form-label">Status</label>
            <select class="form-control @error('is_active') is-invalid @enderror" id="is_active" name="is_active">
              <option value="1" {{ old('is_active', 1) == 1 ? 'selected' : '' }}>Active</option>
              <option value="0" {{ old('is_active') == 0 ? 'selected' : '' }}>Inactive</option>
            </select>
            @error('is_active')
              <div class="invalid-feedback">{{ $message }}</div>
            @enderror
          </div>
        </div>

        <div class="mb-3">
          <label for="logo" class="form-label">Bank Logo</label>
          <input type="file" class="form-control @error('logo') is-invalid @enderror" 
                 id="logo" name="logo" accept="image/*">
          @error('logo')
            <div class="invalid-feedback">{{ $message }}</div>
          @enderror
          <small class="text-muted">Upload bank logo (JPEG, PNG, GIF - Max 2MB)</small>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <a href="{{ route('admin.bank-accounts.index') }}" class="btn btn-outline-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">Create Bank Account</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
