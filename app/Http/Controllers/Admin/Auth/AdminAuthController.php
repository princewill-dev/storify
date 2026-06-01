<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Requests\Admin\Auth\OnboardRequest;
use App\Http\Requests\Admin\Auth\VerifyOtpRequest;
use App\Mail\OtpMail;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    /**
     * Show the superadmin onboard page.
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function onboard(): View|RedirectResponse
    {

        // Check if a superadmin already exists
        if (User::where('role', 'superadmin')->exists()) {
            return redirect()->route('admin.login')
                ->with('error', 'Superadmin account already exists. Please login.');
        }

        return view('admin.auth.onboard');
    }

    /**
     * Process the superadmin onboarding.
     *
     * @param OnboardRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processOnboard(OnboardRequest $request): RedirectResponse
    {
        // Double-check superadmin doesn't exist
        if (User::where('role', 'superadmin')->exists()) {
            return redirect()->route('admin.login')
                ->with('error', 'Superadmin account already exists. Please login.');
        }

        try {
            // Create the superadmin user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => $request->password, // Will be auto-hashed
                'role' => 'superadmin',
            ]);

            // Automatically create the first vendor account
            try {
                Vendor::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => 'active',
                ]);
            } catch (\Throwable $ve) {
                Log::warning('auto_vendor_create_failed_on_onboard', ['error' => $ve->getMessage()]);
            }

            // Log the activity
            ActivityLogger::log(
                action: 'superadmin_onboarded',
                description: "Superadmin account created for {$user->name}",
                metadata: [
                    'user_uuid' => $user->uuid,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                userId: $user->id
            );

            return redirect()->route('admin.login')
                ->with('success', 'Superadmin account created successfully! You can now login.');

        } catch (\Exception $e) {
            // Log the error but don't expose details to user
            Log::error('Superadmin onboarding failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('error', 'An error occurred during registration. Please try again.');
        }
    }

    /**
     * Show the login page.
     *
     * @return \Illuminate\View\View
     */
    public function login(): View|RedirectResponse
    {
        if (Auth::guard('web')->check() && Auth::user()->role === 'superadmin') {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    /**
     * Show the forgot password page (superadmin reset via OTP).
     */
    public function showForgotPassword(): View
    {
        return view('admin.auth.forgot-password');
    }

    /**
     * Handle forgot password: generate OTP and email it.
     */
    public function processForgotPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $data['email'])->where('role', 'superadmin')->first();
        if (!$user) {
            Log::warning('Password reset requested for non-existent/unauthorized email', [
                'email' => substr($data['email'], 0, 3) . '***',
                'ip_address' => $request->ip(),
            ]);
            return back()->withInput()->with('error', 'If the email exists, a verification code will be sent.');
        }

        try {
            $ttl = 10; // minutes
            $otp = OtpService::generate($user->email, 'password_reset', $ttl);
            Mail::to($user->email)->queue(new OtpMail($otp->code, $ttl));

            ActivityLogger::log(
                action: 'otp_requested',
                description: 'Password reset OTP requested',
                metadata: ['type' => 'password_reset'],
                userId: $user->id
            );
            session(['reset_email' => $user->email]);
            return redirect()->route('admin.password.reset')
                ->with('success', 'Verification code sent to your email.');
        } catch (\Exception $e) {
            Log::error('Password reset OTP request failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Unable to process request. Please try again.');
        }
    }

    /**
     * Show enter OTP and new password form.
     */
    public function showResetPassword(): View|RedirectResponse
    {
        if (!session('reset_email')) {
            return redirect()->route('admin.password.forgot')->with('error', 'Start by entering your email.');
        }
        return view('admin.auth.reset-password', ['email' => session('reset_email')]);
    }

    /**
     * Process reset with OTP and new password.
     */
    public function processResetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|max:10',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $data['email'])->where('role', 'superadmin')->first();
        if (!$user) {
            return back()->withInput($request->except('password','password_confirmation','otp'))->with('error', 'Invalid request.');
        }

        try {
            if (!OtpService::verify($data['email'], $data['otp'], 'password_reset')) {
                return back()->withInput($request->except('password','password_confirmation'))
                    ->with('error', 'Invalid or expired verification code.');
            }

            $user->password = $data['password']; // hashed by model mutator
            $user->save();

            ActivityLogger::log(
                action: 'password_reset',
                description: 'Superadmin password reset via OTP',
                metadata: [],
                userId: $user->id
            );

            session()->forget('reset_email');

            return redirect()->route('admin.login')->with('success', 'Password reset successful. You can now login.');
        } catch (\Exception $e) {
            Log::error('Password reset failed', ['error' => $e->getMessage()]);
            return back()->withInput($request->except('password','password_confirmation'))
                ->with('error', 'Unable to reset password. Please try again.');
        }
    }

    /**
     * Process login and send OTP.
     *
     * @param LoginRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processLogin(LoginRequest $request): RedirectResponse
    {
        try {
            // Find user by email
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                Log::warning('Login attempt with non-existent email', [
                    'email' => substr($request->email, 0, 3) . '***',
                    'ip_address' => $request->ip(),
                ]);

                return back()
                    ->withInput($request->only('email'))
                    ->with('error', 'Invalid credentials.');
            }

            // Verify password
            if (!Hash::check($request->password, $user->password)) {
                Log::warning('Login attempt with incorrect password', [
                    'user_id' => $user->id,
                    'email' => substr($user->email, 0, 3) . '***',
                    'ip_address' => $request->ip(),
                ]);

                ActivityLogger::log(
                    action: 'login_failed',
                    description: 'Failed login attempt - incorrect password',
                    metadata: ['reason' => 'invalid_password'],
                    userId: $user->id
                );

                return back()
                    ->withInput($request->only('email'))
                    ->with('error', 'Invalid credentials.');
            }

            // Generate OTP
            $otp = OtpService::generate($user->email, 'login', 10);

            // Send OTP via email (queued)
            Mail::to($user->email)->send(new OtpMail($otp->code, 10));

            // Log OTP request
            ActivityLogger::log(
                action: 'otp_requested',
                description: 'Login OTP requested',
                metadata: ['type' => 'login'],
                userId: $user->id
            );

            Log::info('Login OTP sent', [
                'user_id' => $user->id,
                'email' => substr($user->email, 0, 3) . '***',
                'ip_address' => $request->ip(),
            ]);

            session(['otp_last_sent_at' => time()]);

            return redirect()
                ->route('admin.verify-otp')
                ->with('email', $user->email)
                ->with('success', 'Verification code sent to your email. Please check your inbox.');

        } catch (\Exception $e) {
            Log::error('Login process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->with('error', 'An error occurred. Please try again.');
        }
    }

    /**
     * Show OTP verification page.
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function showVerifyOtp(): View|RedirectResponse
    {
        if (!session('email')) {
            return redirect()->route('admin.login')
                ->with('error', 'Please login first.');
        }

        return view('admin.auth.verify-otp');
    }

    /**
     * Verify OTP and login user.
     *
     * @param VerifyOtpRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verifyOtp(VerifyOtpRequest $request): RedirectResponse
    {
        try {
            // Verify OTP
            if (!OtpService::verify($request->email, $request->otp, 'login')) {
                Log::warning('OTP verification failed', [
                    'email' => substr($request->email, 0, 3) . '***',
                    'ip_address' => $request->ip(),
                ]);

                return back()
                    ->withInput($request->only('email'))
                    ->with('error', 'Invalid or expired verification code.');
            }

            // Find and login user
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return redirect()->route('admin.login')
                    ->with('error', 'User not found.');
            }

            // Login user
            Auth::login($user, true);

            // Log successful login
            ActivityLogger::log(
                action: 'login_success',
                description: "User logged in successfully",
                metadata: [
                    'auth_method' => 'otp',
                    'user_role' => $user->role,
                ],
                userId: $user->id
            );

            Log::info('User logged in successfully', [
                'user_id' => $user->id,
                'email' => substr($user->email, 0, 3) . '***',
                'role' => $user->role,
                'ip_address' => $request->ip(),
            ]);

            // Clear session email
            session()->forget('email');

            return redirect()->route('admin.dashboard')
                ->with('success', 'Welcome back, ' . $user->name . '!');

        } catch (\Exception $e) {
            Log::error('OTP verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->with('error', 'An error occurred. Please try again.');
        }
    }

    public function resendOtp(Request $request): RedirectResponse
    {
        $email = $request->input('email') ?? session('email');

        if (!$email) {
            return redirect()->route('admin.login')
                ->with('error', 'Please login first.');
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            return redirect()->route('admin.login')
                ->with('error', 'User not found.');
        }

        $cooldownSeconds = 60;
        $lastSent = session('otp_last_sent_at');
        if ($lastSent && (time() - $lastSent) < $cooldownSeconds) {
            $remaining = $cooldownSeconds - (time() - $lastSent);
            return redirect()->route('admin.verify-otp')
                ->with('email', $email)
                ->with('error', 'Please wait ' . $remaining . ' seconds before requesting a new code.');
        }

        try {
            $otp = OtpService::generate($email, 'login', 10);
            Mail::to($email)->send(new OtpMail($otp->code, 10));

            ActivityLogger::log(
                action: 'otp_requested',
                description: 'Login OTP re-requested',
                metadata: ['type' => 'login', 'reason' => 'resend'],
                userId: $user->id
            );

            Log::info('Login OTP resent', [
                'user_id' => $user->id,
                'email' => substr($user->email, 0, 3) . '***',
                'ip_address' => $request->ip(),
            ]);

            session(['otp_last_sent_at' => time()]);

            return redirect()->route('admin.verify-otp')
                ->with('email', $email)
                ->with('success', 'A new verification code has been sent to your email.');

        } catch (\Exception $e) {
            Log::error('OTP resend failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('admin.verify-otp')
                ->with('email', $email)
                ->with('error', 'Unable to resend code. Please try again.');
        }
    }

    /**
     * Logout user.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(): RedirectResponse
    {
        $user = Auth::user();

        if ($user) {
            ActivityLogger::log(
                action: 'logout',
                description: 'User logged out',
                metadata: [],
                userId: $user->id
            );

            Log::info('User logged out', [
                'user_id' => $user->id,
            ]);
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect('/superadmin')
            ->with('success', 'You have been logged out successfully.');
    }
}
