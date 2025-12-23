@extends('vendors.layout')
@section('subtitle', 'Create Category')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Create category</h4>
    <a href="{{ route('vendor.categories.index', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-light">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="{{ route('vendor.categories.store', ['vendor' => $vendor]) }}">
        @csrf
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Store</label>
            <select name="store_id" class="form-select" required>
              <option value="">Select store</option>
              @foreach($stores as $s)
                <option value="{{ $s->id }}" @selected(old('store_id', $selectedStoreId ?? null)==$s->id)>{{ $s->name }}</option>
              @endforeach
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <option value="active" @selected(old('status','active')=='active')>active</option>
              <option value="inactive" @selected(old('status')=='inactive')>inactive</option>
            </select>
          </div>
        </div>
        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Create</button>
          <a class="btn btn-light" href="{{ route('vendor.categories.index', ['vendor' => $vendor]) }}">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
