@extends('home.layout')
@section('title', 'Delivery Information')

@section('content')

<br>
<br>
<br>
<br>

<div class="container py-4">
  <h4>Delivery Information</h4>
  <p class="text-muted">List ID: <code>{{ $request->list_id }}</code></p>
  <form method="post" action="{{ route('shop4me.delivery.save', ['list' => $request->list_id]) }}">
    @csrf
    <div class="mb-3">
      <label class="form-label">Address</label>
      <input type="text" class="form-control" name="address_line" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Landmark</label>
      <input type="text" class="form-control" name="landmark">
    </div>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Alt Phone</label>
        <input type="text" class="form-control" name="alt_phone">
      </div>
      <div class="col-md-6">
        <label class="form-label">Map Link</label>
        <input type="url" class="form-control" name="map_link" placeholder="https://maps.google.com/?q=...">
      </div>
    </div>
    <div class="mt-3">
      <button class="btn btn-primary" type="submit">Save & Continue</button>
    </div>
  </form>
</div>
@endsection
