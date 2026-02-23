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
                            <div class="input-group">
                                <input id="password" name="password" required class="form-control" placeholder="Enter password" type="password" minlength="8" style="border-right: 0;">
                                <button class="btn btn-outline-secondary toggle-password d-flex align-items-center justify-content-center" type="button" data-target="password" style="background: transparent; border-left: 0; z-index: 5; padding-left: 15px; padding-right: 15px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                                      <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                      <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                                    </svg>
                                </button>
                            </div>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="m-b40">
                            <label class="label-title">Confirm Password</label>
                            <div class="input-group">
                                <input id="password_confirmation" name="password_confirmation" required class="form-control" placeholder="Re-enter password" type="password" minlength="8" style="border-right: 0;">
                                <button class="btn btn-outline-secondary toggle-password d-flex align-items-center justify-content-center" type="button" data-target="password_confirmation" style="background: transparent; border-left: 0; z-index: 5; padding-left: 15px; padding-right: 15px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                                      <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                      <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                                    </svg>
                                </button>
                            </div>
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

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const eyeSvg = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
        const eyeSlashSvg = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l-.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';

        const togglePasswordButtons = document.querySelectorAll('.toggle-password');
        togglePasswordButtons.forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const targetInput = document.getElementById(targetId);
                const svg = this.querySelector('svg');
                
                if (targetInput.type === 'password') {
                    targetInput.type = 'text';
                    svg.innerHTML = eyeSlashSvg;
                } else {
                    targetInput.type = 'password';
                    svg.innerHTML = eyeSvg;
                }
            });
        });
    });
</script>
@endpush