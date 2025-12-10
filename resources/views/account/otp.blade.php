@extends('home.layout')
@section('title', 'Verify Email')

@section('content')

<br><br><br><br><br><br><br><br>
<div class="page-content">
    <section class="px-3">
        <div class="col-md-6 mx-auto">
            <div class="login-area">
                <h2 class="text-secondary text-center">Enter OTP</h2>
                <p class="text-center m-b30">
                    We sent a 6-digit code to 
                    @if(session('pending_customer_email'))
                        <strong>{{ session('pending_customer_email') }}</strong>
                    @else
                        your email
                    @endif
                </p>
                
                @if(session('status'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('status') }}
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
                
                <form method="post" action="{{ route('account.verify', ['list' => $listId]) }}">
                    @csrf
                    <div class="m-b25">
                        <label class="label-title">Verification Code</label>
                        <input name="otp" required class="form-control" placeholder="Enter 6-digit code" type="text" maxlength="6" autofocus>
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-secondary btnhover text-uppercase w-100">Verify Email</button>
                    </div>
                </form>

                <form method="post" action="{{ route('account.verify.resend', ['list' => $listId]) }}" class="mt-3 text-center">
                    @csrf
                    <button type="submit" class="btn btn-link">Resend code</button>
                </form>
            </div>
        </div>
    </section>
</div>
<br><br><br><br>
@endsection
