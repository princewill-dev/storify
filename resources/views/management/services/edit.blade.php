@extends('management.layout')
@section('subtitle', 'Edit service')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Edit service</h4>
    <a href="{{ route('management.services.index') }}" class="btn btn-light">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <form method="post" action="{{ route('management.services.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Store</label>
            <div class="form-control bg-light">{{ $service->store->name ?? 'N/A' }}</div>
          </div>
          
          <div class="col-md-6">
             <label class="form-label">Name</label>
             <input type="text" name="name" class="form-control" value="{{ old('name', $service->name) }}" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Amount</label>
            <div class="input-group">
              <input type="number" name="amount" step="0.01" min="0.01" class="form-control" value="{{ old('amount', number_format($service->amount, 2, '.', '')) }}" required>
              <select name="currency_id" class="form-select" style="max-width: 140px;">
                @foreach(($currencies ?? []) as $cur)
                  <option value="{{ $cur->id }}" @selected(old('currency_id', $service->currency_id) == $cur->id)>{{ $cur->code }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <div class="col-md-4">
             <label class="form-label">Status</label>
             <select name="status" class="form-select" required>
               <option value="active" @selected(old('status', $service->status)=='active')>Active</option>
               <option value="inactive" @selected(old('status', $service->status)=='inactive')>Inactive</option>
             </select>
          </div>

          <div class="col-12">
            <label class="form-label">Description</label>
            <input id="service-description" type="hidden" name="description" value="{{ old('description', $service->description) }}">
            <trix-editor input="service-description" class="form-control"></trix-editor>
          </div>

          <div class="col-12">
            <label class="form-label">Existing images</label>
            <div class="d-flex flex-wrap gap-3">
              @forelse($service->images as $img)
                <div class="border rounded p-2" style="width:160px;">
                  <img src="{{ asset('storage/'.$img->path) }}" alt="" class="img-fluid mb-2" style="max-height:120px;object-fit:contain;">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="primary_image_id" value="{{ $img->id }}" id="prim{{ $img->id }}" @checked($img->is_primary)>
                    <label class="form-check-label" for="prim{{ $img->id }}">Primary</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="delete_image_ids[]" value="{{ $img->id }}" id="del{{ $img->id }}">
                    <label class="form-check-label" for="del{{ $img->id }}">Delete</label>
                  </div>
                </div>
              @empty
                <div class="text-muted">No images uploaded yet.</div>
              @endforelse
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Add images</label>
            <input type="file" name="images[]" class="form-control" accept="image/*" multiple>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button class="btn btn-primary" type="submit">Save changes</button>
          <a href="{{ route('management.services.index') }}" class="btn btn-light">Back</a>
        </div>
      </form>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.min.css">
<script src="https://cdn.jsdelivr.net/npm/trix@2.0.8/dist/trix.umd.min.js"></script>
@endsection
