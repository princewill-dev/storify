@extends('home.layout')
@section('title', 'Register')

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
                    <h2 class="text-secondary text-center">Create Account</h2>
                    <p class="text-center m-b30">
                        @if(request('checkout') == '1' || session('checkout_redirect'))
                            Create an account to continue with your checkout
                        @else
                            Please enter your details to proceed
                        @endif
                    </p>
                    
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            @foreach($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif
                    
                    <form method="post" action="{{ route('account.register', ['list' => $listId]) }}">
                        @csrf
                        <div class="m-b25">
                            <label class="label-title">Full Name</label>
                            <input name="name" required class="form-control" placeholder="Full Name" type="text" value="{{ old('name') }}">
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Email Address</label>
                            <input name="email" required class="form-control" placeholder="Email Address" type="email" value="{{ old('email') }}">
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Phone Number</label>
                            <input name="phone" required class="form-control" placeholder="Phone Number" type="text" value="{{ old('phone') }}">
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Password</label>
                            <input name="password" required class="form-control" placeholder="Enter password" type="password" minlength="8">
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="m-b40">
                            <label class="label-title">Confirm Password</label>
                            <input name="password_confirmation" required class="form-control" placeholder="Re-enter password" type="password" minlength="8">
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-secondary btnhover text-uppercase me-2">Register</button>
                        </div>

                        <hr>
                        <p class="text-center">Already have an account? <a href="{{ route('account.login', isset($flow) && $flow ? ['flow' => $flow] : []) }}">Sign In</a></p>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>
<br>
<br>
<br>
<br>

@endsection