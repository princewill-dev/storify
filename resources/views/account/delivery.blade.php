@extends('home.layout')
@section('title', 'Delivery Information')

@section('content')

<br><br><br><br><br><br><br><br>
<div class="page-content">
    <section class="px-3">
        <div class="row align-center-center">
            <div class="col-lg-8 col-md-8 mx-auto">
                <div class="login-area">
                    <h2 class="text-secondary text-center">Where should we deliver?</h2>
                    <p class="text-center m-b30">Provide detailed address and GPS/landmark</p>
                    <form method="post" action="{{ route('shop4me.delivery.save', ['list' => $request->list_id]) }}">
                        @csrf
                        <div class="m-b25">
                            <label class="label-title">Address</label>
                            <input name="address_line" required class="form-control" placeholder="Street, number, area" type="text" value="{{ old('address_line') }}">
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Landmark</label>
                            <input name="landmark" class="form-control" placeholder="Nearby landmark" type="text" value="{{ old('landmark') }}">
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Alternate Phone</label>
                            <input name="alt_phone" class="form-control" placeholder="Alternate contact number" type="text" value="{{ old('alt_phone') }}">
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Map Link</label>
                            <input name="map_link" class="form-control" placeholder="https://maps.google.com/?q=..." type="url" value="{{ old('map_link') }}">
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-secondary btnhover text-uppercase me-2">Save & Continue</button>
                        </div>
                    </form>
                </div>  
            </div>
        </div>
    </section>
</div>
<br><br><br><br><br><br><br><br>
@endsection
