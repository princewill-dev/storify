@extends('admin.layout')
@section('subtitle', 'business types')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Create business type</h4>
    <a href="{{ route('admin.business-types.index') }}" class="btn btn-light">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="{{ route('admin.business-types.store') }}">
        @csrf
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
          </div>
        </div>
        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Create</button>
          <a class="btn btn-light" href="{{ route('admin.business-types.index') }}">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
