@extends('home.layout')
@section('title', 'Login')

@section('content')

<br>
<br>
<br>
<br>
<br>
<br>
<br>
<br>

<div class="page-content">
    <section class="px-3">
        <div class="row align-center-center">
            <div class="col-md-6 mx-auto">
                <div class="login-area">
                    <h2 class="text-secondary text-center">Welcome Back Customer</h2>
                    <p class="text-center m-b30">
                        @if(session('checkout_redirect'))
                            Please login to continue with your checkout
                        @else
                            Please login to your account
                        @endif
                    </p>
                    
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

                    <form method="post" action="{{ route('account.login') }}">
                        @csrf
                        <div class="m-b25">
                            <label class="label-title">Email Address</label>
                            <input name="email" required class="form-control" placeholder="Email Address" type="email" value="{{ old('email') }}" autofocus>
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Password</label>
                            <input name="password" required class="form-control" placeholder="Password" type="password">
                        </div>
                        <div class="m-b25 d-flex justify-content-between align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                                <label class="form-check-label" for="remember">
                                    Remember Me
                                </label>
                            </div>
                            <a href="{{ route('account.forgot-password') }}" class="text-primary">Forgot Password?</a>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-secondary btnhover text-uppercase me-2 w-100">Login</button>
                        </div>

                        <hr>
                        <p class="text-center">Don't have an account? 
                            @if(isset($flow) && $flow)
                                <a href="{{ route('account.register', ['flow' => $flow]) }}">Sign Up</a>
                            @elseif(session('checkout_redirect'))
                                <a href="{{ route('account.register') }}?checkout=1">Sign Up</a>
                            @else
                                <a href="{{ route('account.register') }}">Sign Up</a>
                            @endif
                        </p>
                        
                    </form>
                    
                </div>
                <br>
                <p class="text-center">Are you a vendor? <a href="{{ route('management.auth.login') }}">Login</a></p>
            </div>
        </div>
    </section>
</div>

<br>
<br>
<br>
<br>

@endsection
