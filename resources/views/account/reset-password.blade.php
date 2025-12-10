@extends('home.layout')
@section('title', 'Reset Password')

@section('content')

<br><br><br><br>

<div class="page-content">
    <section class="px-3">
        <div class="row align-center-center">
            <div class="col-md-6 mx-auto">
                <div class="login-area">
                    <h2 class="text-secondary text-center">Reset Password</h2>
                    <p class="text-center m-b30">Enter your new password</p>
                    
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            @foreach($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    @endif

                    <form method="post" action="{{ route('account.reset-password.form', $token) }}">
                        @csrf
                        <div class="m-b25">
                            <label class="label-title">New Password</label>
                            <input name="password" required class="form-control" placeholder="Enter new password" type="password" minlength="8" autofocus>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="m-b25">
                            <label class="label-title">Confirm Password</label>
                            <input name="password_confirmation" required class="form-control" placeholder="Confirm new password" type="password" minlength="8">
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-secondary btnhover text-uppercase w-100">Reset Password</button>
                        </div>

                        <hr>
                        <p class="text-center"><a href="{{ route('account.login') }}">Back to Login</a></p>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

@endsection
