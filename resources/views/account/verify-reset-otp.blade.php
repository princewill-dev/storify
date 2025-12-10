@extends('home.layout')
@section('title', 'Verify OTP')

@section('content')

<br><br><br><br>

<div class="page-content">
    <section class="px-3">
        <div class="row align-center-center">
            <div class="col-md-6 mx-auto">
                <div class="login-area">
                    <h2 class="text-secondary text-center">Verify OTP</h2>
                    <p class="text-center m-b30">Enter the 6-digit code sent to {{ session('email') }}</p>
                    
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            @foreach($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <form method="post" action="{{ route('account.reset-password.verify') }}">
                        @csrf
                        <input type="hidden" name="email" value="{{ session('email') }}">
                        <div class="m-b25">
                            <label class="label-title">OTP Code</label>
                            <input name="otp" required class="form-control text-center" placeholder="Enter 6-digit OTP" type="text" maxlength="6" pattern="[0-9]{6}" autofocus>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-secondary btnhover text-uppercase w-100">Verify OTP</button>
                        </div>

                        <hr>
                        <p class="text-center">Didn't receive the code? <a href="{{ route('account.forgot-password') }}">Resend</a></p>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
