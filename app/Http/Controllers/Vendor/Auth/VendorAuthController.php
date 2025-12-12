<?php

namespace App\Http\Controllers\Vendor\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\Auth\LoginRequest;
use App\Http\Requests\Vendor\Auth\RegisterRequest;
use App\Http\Requests\Vendor\Auth\VerifyOtpRequest;
use App\Mail\OtpMail;
use App\Mail\VendorLoginAlert;
use App\Models\Vendor;
use App\Services\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VendorAuthController extends Controller
{
    public function showRegister(): View|RedirectResponse
    {
        if (Auth::guard('vendor')->check()) {
            return redirect()->route('vendor.dashboard');
        }

        return view('vendors.auth.onboard');
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $vendor = Vendor::create([
                'name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'ip_address' => $request->ip(),
                'status' => Vendor::STATUS_PENDING,
            ]);
        } catch (QueryException $e) {
            if (($e->getCode() === '23000') || str_contains($e->getMessage(), 'Duplicate entry')) {
                Log::warning('vendor.register.duplicate_data', ['email' => $data['email'], 'phone' => $data['phone'], 'error' => $e->getMessage()]);
                return back()->withInput($request->except('password'))
                    ->with('error', 'We already have an account with that phone number or email.');
            }

            throw $e;
        }

        Log::info('vendor.register.initiated', ['vendor_id' => $vendor->id, 'email' => $vendor->email]);

        $this->sendOtp($vendor->email, 'vendor_email_verification');

        session(['pending_vendor_email' => $vendor->email]);

        return redirect()->route('vendor.auth.verify-otp', ['vendor' => $vendor])
            ->with('success', 'We sent a verification code to your email. Enter it below to continue.');
    }

    public function showLogin(): View
    {
        return view('vendors.auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');

        if (!Auth::guard('vendor')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Invalid credentials.');
        }

        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();

        if (!$vendor->is_verified) {
            Auth::guard('vendor')->logout();
            $this->sendOtp($vendor->email, 'vendor_email_verification');
            session(['pending_vendor_email' => $vendor->email]);

            return redirect()->route('vendor.auth.verify-otp')
                ->with('warning', 'Please verify your email to continue. We just re-sent the code.');
        }

        $vendor->forceFill(['last_login' => now()])->save();

        if ($vendor->status === Vendor::STATUS_SUSPENDED || $vendor->status === Vendor::STATUS_DELETED) {
            Auth::guard('vendor')->logout();
            return back()->withInput($request->only('email'))
                ->with('error', 'Your account is currently ' . $vendor->status . '. Please contact support.');
        }

        $ipAddress = $request->ip();
        $userAgent = (string) $request->userAgent();

        Log::info('vendor.login.success', [
            'vendor_id' => $vendor->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit($userAgent, 255),
        ]);

        Mail::to($vendor->email)
            ->queue(new VendorLoginAlert($vendor, $ipAddress, $userAgent));

        $intended = session()->pull('url.intended', route('vendor.dashboard'));

        Auth::guard('vendor')->logout();
        $this->sendOtp($vendor->email, 'vendor_login');

        session([
            'pending_vendor_login_email' => $vendor->email,
            'pending_vendor_login_redirect' => $intended,
            'otp_context' => 'vendor_login',
        ]);

        return redirect()->route('vendor.auth.verify-otp', ['vendor' => $vendor])
            ->with('info', 'We just emailed you a one-time code. Enter it to complete login.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('vendor')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('vendor.auth.login')
            ->with('success', 'You have been logged out.');
    }

    public function showForgotPassword(): View
    {
        return view('vendors.auth.forgot-password');
    }

    public function sendResetOtp(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $vendor = Vendor::where('email', $request->email)->first();
        if (!$vendor) {
            return back()->with('status', 'If that email is registered, we will send a verification code.')->withInput();
        }

        $this->sendOtp($vendor->email, 'vendor_password_reset');
        session(['vendor_password_reset_email' => $vendor->email]);

        return redirect()->route('vendor.auth.reset-password')
            ->with('success', 'Verification code sent. Please check your email.');
    }

    public function showResetPassword(): View|RedirectResponse
    {
        if (!session('vendor_password_reset_email')) {
            return redirect()->route('vendor.auth.forgot-password')
                ->with('error', 'Start by entering your email address.');
        }

        return view('vendors.auth.reset-password', [
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

        $vendor = Vendor::where('email', $data['email'])->first();
        if (!$vendor) {
            return back()->with('error', 'Invalid reset request.')->withInput($request->except('password', 'password_confirmation'));
        }

        if (!OtpService::verify($data['email'], $data['otp'], 'vendor_password_reset')) {
            return back()->with('error', 'Invalid or expired verification code.')
                ->withInput($request->except('password', 'password_confirmation'));
        }

        $vendor->forceFill(['password' => $data['password']])->save();
        session()->forget('vendor_password_reset_email');

        return redirect()->route('vendor.auth.login')->with('success', 'Password updated. You can now login.');
    }

    public function showVerifyOtp(Vendor $vendor): View|RedirectResponse
    {
        $context = session('otp_context', 'vendor_email_verification');

        if ($vendor->is_verified && $context !== 'vendor_login') {
            return redirect()->route('vendor.dashboard')
                ->with('status', 'Email already verified.');
        }

        return view('vendors.auth.verify-otp', [
            'vendor' => $vendor,
            'email' => session('pending_vendor_login_email', session('pending_vendor_email', $vendor->email)),
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request, Vendor $vendor): RedirectResponse
    {
        $context = session('otp_context', 'vendor_email_verification');
        $otpType = $context === 'vendor_login' ? 'vendor_login' : 'vendor_email_verification';

        if (!OtpService::verify($vendor->email, $request->otp, $otpType)) {
            return back()->with('error', 'Invalid or expired verification code.')
                ->withInput($request->only('email'));
        }

        session()->forget('otp_context');

        if ($context === 'vendor_login') {
            Auth::guard('vendor')->login($vendor);
            $vendor->forceFill(['last_login' => now()])->save();

            $redirect = session()->pull('pending_vendor_login_redirect', route('vendor.dashboard'));
            session()->forget('pending_vendor_login_email');

            return redirect()->to($redirect)
                ->with('success', 'Welcome back, ' . $vendor->name . '!');
        }

        if ($vendor) {
            $vendor->forceFill(['is_verified' => true, 'email_verified_at' => now()])->save();
            session()->forget('pending_vendor_email');
            Auth::guard('vendor')->login($vendor);
            $vendor->forceFill(['last_login' => now()])->save();
        }

        return redirect()->route('vendor.dashboard')
            ->with('success', 'Email verified successfully. Welcome!');
    }

    public function resendOtp(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->sendOtp($vendor->email, 'vendor_email_verification');

        return back()->with('success', 'We sent a new verification code to ' . $vendor->email . '.');
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
