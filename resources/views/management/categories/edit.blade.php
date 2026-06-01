@extends('management.layout')
@section('subtitle', 'Edit Categories')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Edit category</h4>
    <a href="{{ route('management.categories.index') }}" class="btn btn-light">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="{{ route('management.categories.update', $category) }}">
        @csrf
        @method('PUT')
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Store</label>
            <select name="store_id" class="form-select" required>
              @foreach($stores as $s)
                <option value="{{ $s->id }}" @selected(old('store_id', $category->store_id)==$s->id)>{{ $s->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
              <option value="active" @selected(old('status', $category->status)=='active')>active</option>
              <option value="inactive" @selected(old('status', $category->status)=='inactive')>inactive</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $category->name) }}" required>
          </div>
        </div>
        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Save changes</button>
          <a class="btn btn-light" href="{{ route('management.categories.index') }}">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
