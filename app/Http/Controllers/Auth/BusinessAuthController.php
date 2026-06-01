<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Mail\OtpMail;
use App\Mail\VendorLoginAlert;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BusinessAuthController extends Controller
{
    public function showRegister(): View|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->to($this->redirectAfterAuth());
        }

        return view('auth.business.register');
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $user = User::create([
                'name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => User::ROLE_BUSINESS_OWNER,
                'status' => 'active',
                'is_verified' => false,
                'ip_address' => $request->ip(),
            ]);
        } catch (QueryException $e) {
            if (($e->getCode() === '23000') || str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::warning('register.duplicate_data', ['email' => $data['email'], 'phone' => $data['phone'], 'error' => $e->getMessage()]);
                return back()->withInput($request->except('password'))
                    ->with('error', 'We already have an account with that phone number or email.');
            }
            throw $e;
        }

        Log::info('register.initiated', ['user_id' => $user->id, 'email' => $user->email]);

        $this->sendOtp($user->email, 'vendor_email_verification');
        session(['pending_vendor_email' => $user->email]);

        return redirect()->route('management.auth.verify-otp')
            ->with('success', 'We sent a verification code to your email. Enter it below to continue.');
    }

    public function showLogin(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->to($this->redirectAfterAuth());
        }

        return view('auth.business.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');

        if (!Auth::guard('web')->attempt($credentials, false)) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Invalid credentials.');
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if ($user->isStaff()) {
            if ($user->status === 'suspended') {
                Auth::guard('web')->logout();
                return back()->withInput($request->only('email'))
                    ->with('error', 'Your account has been suspended. Contact your administrator.');
            }

            $user->forceFill(['last_login_at' => now()])->save();

            Log::info('staff.login.success', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            if ($user->hasRole('Cashier')) {
                $hasPosStore = $user->assignedStores()->where('pos_enabled', true)->exists();

                return redirect()->intended(
                    $hasPosStore ? route('staff.pos') : route('staff.dashboard')
                )->with('success', 'Welcome, ' . $user->name . '!');
            }

            return redirect()->intended(route('staff.dashboard'))
                ->with('success', 'Welcome, ' . $user->name . '!');
        }

        if (!$user->isBusinessOwner() && !$user->isAdmin()) {
            Auth::guard('web')->logout();
            return back()->withInput($request->only('email'))
                ->with('error', 'This account is not a business account.');
        }

        if (!$user->is_verified) {
            Auth::guard('web')->logout();
            $this->sendOtp($user->email, 'vendor_email_verification');
            session(['pending_vendor_email' => $user->email]);

            return redirect()->route('management.auth.verify-otp')
                ->with('warning', 'Please verify your email to continue. We just re-sent the code.');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->status === 'suspended' || $user->status === 'deleted') {
            Auth::guard('web')->logout();
            return back()->withInput($request->only('email'))
                ->with('error', 'Your account is currently ' . $user->status . '. Please contact support.');
        }

        $ipAddress = $request->ip();
        $userAgent = (string) $request->userAgent();

        Log::info('login.success', [
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit($userAgent, 255),
        ]);

        $intended = session()->pull('url.intended', route('management.dashboard'));

        Auth::guard('web')->logout();
        $this->sendOtp($user->email, 'vendor_login');

        session([
            'pending_vendor_login_email' => $user->email,
            'pending_vendor_login_redirect' => $intended,
            'otp_context' => 'vendor_login',
        ]);

        return redirect()->route('management.auth.verify-otp')
            ->with('info', 'We just emailed you a one-time code. Enter it to complete login.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('management.auth.login')
            ->with('success', 'You have been logged out.');
    }

    public function showForgotPassword(): View
    {
        return view('auth.business.forgot-password');
    }

    public function sendResetOtp(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $vendor = User::where('email', $request->email)->first();
        if (!$vendor) {
            return back()->with('status', 'If that email is registered, we will send a verification code.')->withInput();
        }

        $this->sendOtp($vendor->email, 'vendor_password_reset');
        session(['vendor_password_reset_email' => $vendor->email]);

        return redirect()->route('management.auth.reset-password')
            ->with('success', 'Verification code sent. Please check your email.');
    }

    public function showResetPassword(): View|RedirectResponse
    {
        if (!session('vendor_password_reset_email')) {
            return redirect()->route('management.auth.forgot-password')
                ->with('error', 'Start by entering your email address.');
        }

        return view('auth.business.reset-password', [
            'email' => session('vendor_password_reset_email'),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $vendor = User::where('email', $data['email'])->first();
        if (!$vendor) {
            return back()->with('error', 'Invalid reset request.')->withInput($request->except('password', 'password_confirmation'));
        }

        if (!OtpService::verify($data['email'], $data['otp'], 'vendor_password_reset')) {
            return back()->with('error', 'Invalid or expired verification code.')
                ->withInput($request->except('password', 'password_confirmation'));
        }

        $vendor->forceFill(['password' => $data['password']])->save();
        session()->forget('vendor_password_reset_email');

        return redirect()->route('management.auth.login')->with('success', 'Password updated. You can now login.');
    }

    public function showVerifyOtp(): View|RedirectResponse
    {
        $context = session('otp_context', 'vendor_email_verification');
        $email = session('pending_vendor_login_email', session('pending_vendor_email'));

        if (!$email) {
            return redirect()->route('management.auth.login')
                ->with('error', 'No pending verification. Please log in first.');
        }

        $user = User::where('email', $email)->first();

        if ($user && $user->is_verified && $context !== 'vendor_login') {
            return redirect()->route('management.dashboard')
                ->with('status', 'Email already verified.');
        }

        return view('auth.business.verify-otp', [
            'email' => $email,
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): RedirectResponse
    {
        $context = session('otp_context', 'vendor_email_verification');
        $otpType = $context === 'vendor_login' ? 'vendor_login' : 'vendor_email_verification';
        $email = $request->email;

        if (!OtpService::verify($email, $request->otp, $otpType)) {
            return back()->with('error', 'Invalid or expired verification code.')
                ->withInput($request->only('email'));
        }

        session()->forget('otp_context');

        $user = User::where('email', $email)->first();

        if ($context === 'vendor_login') {
            if ($user) {
                Auth::guard('web')->login($user);
                $user->forceFill(['last_login_at' => now()])->save();
            }

            $redirect = session()->pull('pending_vendor_login_redirect', $this->redirectAfterAuth());
            session()->forget('pending_vendor_login_email');

            return redirect()->to($redirect)
                ->with('success', 'Welcome back, ' . ($user?->name ?? ''));
        }

        if ($user) {
            $user->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();
            Auth::guard('web')->login($user);
            $user->forceFill(['last_login_at' => now()])->save();
        }

        session()->forget('pending_vendor_email');

        return redirect()->to($this->redirectAfterAuth())
            ->with('success', 'Email verified successfully. Welcome!');
    }

    protected function redirectAfterAuth(): string
    {
        $user = auth()->user();

        if ($user && $user->isStaff()) {
            if ($user->hasRole('Cashier')) {
                $hasPosStore = $user->assignedStores()->where('pos_enabled', true)->exists();
                return $hasPosStore ? route('staff.pos') : route('staff.dashboard');
            }
            return route('staff.dashboard');
        }

        if ($user && !$user->business_id) {
            return route('management.setup');
        }

        return route('management.dashboard');
    }

    public function resendOtp(Request $request): RedirectResponse
    {
        $email = session('pending_vendor_login_email', session('pending_vendor_email'));
        if ($email) {
            $this->sendOtp($email, 'vendor_email_verification');
            return back()->with('success', 'We sent a new verification code to ' . $email . '.');
        }

        return back()->with('error', 'No pending email. Please log in first.');
    }

    protected function sendOtp(string $email, string $type): void
    {
        $otp = OtpService::generate($email, $type, 10);

        try {
            Mail::to($email)->queue(new OtpMail($otp->code, 10));
        } catch (\Throwable $e) {
            Log::error('vendor.otp.mail_failed', ['email' => $email, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
