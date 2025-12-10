@extends('home.layout')
@section('title', 'Forgot Password')

@section('content')

<br><br><br><br>

<div class="page-content">
    <section class="px-3">
        <div class="row align-center-center">
            <div class="col-md-6 mx-auto">
                <div class="login-area">
                    <h2 class="text-secondary text-center">Forgot Password?</h2>
                    <p class="text-center m-b30">Enter your email to receive a password reset OTP</p>
                    
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            @foreach($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <form method="post" action="{{ route('account.forgot-password') }}">
                        @csrf
                        <div class="m-b25">
                            <label class="label-title">Email Address</label>
                            <input name="email" required class="form-control" placeholder="Enter your email" type="email" value="{{ old('email') }}" autofocus>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-secondary btnhover text-uppercase w-100">Send OTP</button>
                        </div>

                        <hr>
                        <p class="text-center">Remember your password? <a href="{{ route('account.login') }}">Login</a></p>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
