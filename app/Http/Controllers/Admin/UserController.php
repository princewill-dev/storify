<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Mail\UserPasswordResetMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    private const USER_ROLES = ['business_owner', 'staff'];

    public function index(Request $request): View
    {
        $role = $request->query('role', 'business_owner');
        $status = $request->query('status');
        $verified = $request->query('verified');
        $hasBusiness = $request->query('has_business');
        $subscription = $request->query('subscription');
        $q = trim((string) $request->query('q', ''));

        $query = User::query()
            ->whereIn('role', self::USER_ROLES)
            ->when(in_array($role, self::USER_ROLES, true), fn ($x) => $x->where('role', $role))
            ->when(in_array(strtolower((string) $status), ['active', 'suspended', 'deleted'], true), fn ($x) => $x->where('status', strtolower((string) $status)))
            ->when($verified === 'yes', fn ($x) => $x->where('is_verified', true))
            ->when($verified === 'no', fn ($x) => $x->where('is_verified', false))
            ->when($hasBusiness === 'yes', fn ($x) => $x->whereNotNull('business_id'))
            ->when($hasBusiness === 'no', fn ($x) => $x->whereNull('business_id'))
            ->when($q !== '', function ($x) use ($q) {
                $x->where(fn ($inner) => $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('account_code', 'like', "%{$q}%"));
            })
            ->with(['business.activeSubscription.subscriptionPlan']);

        if ($subscription === 'active') {
            $query->whereHas('business', fn ($b) => $b->whereHas('activeSubscription'));
        } elseif ($subscription === 'trial') {
            $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now());
        } elseif ($subscription === 'none') {
            $query->where(fn ($x) => $x->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', now()))
                ->whereDoesntHave('business', fn ($b) => $b->whereHas('activeSubscription'));
        }

        $users = $query->latest()->paginate(20)->withQueryString();

        $stats = [
            'owners' => User::where('role', 'business_owner')->where('status', '!=', 'deleted')->count(),
            'staff' => User::where('role', 'staff')->where('status', '!=', 'deleted')->count(),
            'suspended' => User::whereIn('role', self::USER_ROLES)->where('status', 'suspended')->count(),
            'unverified' => User::whereIn('role', self::USER_ROLES)->where('is_verified', false)->where('status', '!=', 'deleted')->count(),
        ];

        return view('admin.users.index', compact('users', 'role', 'status', 'verified', 'hasBusiness', 'subscription', 'q', 'stats'));
    }

    public function show(User $user): View
    {
        $this->ensureManaged($user);

        $user->load([
            'business' => fn ($q) => $q->withCount(['stores', 'warehouses', 'users']),
            'business.activeSubscription.subscriptionPlan',
            'business.subscriptions' => fn ($q) => $q->latest()->limit(5),
            'stores',
            'assignedStores',
        ]);

        $payments = $user->business_id
            ? Payment::where('business_id', $user->business_id)->latest()->limit(10)->get()
            : collect();

        $activity = ActivityLog::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere(fn ($inner) => $inner->where('subject_type', User::class)->where('subject_id', $user->id));
            })
            ->latest()
            ->limit(25)
            ->get();

        $ordersCount = $user->business_id
            ? Order::where('business_id', $user->business_id)->count()
            : 0;

        return view('admin.users.show', compact('user', 'payments', 'activity', 'ordersCount'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $old = $user->only(['name', 'email', 'phone']);
        $user->update($data);

        $this->log($request, 'user_updated', $user, "Updated user {$user->name}", $old, $data);

        return back()->with('success', 'User updated.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $data = $request->validate(['reason' => 'required|string|max:2000']);

        if ($this->ownsMainStore($user)) {
            return back()->with('error', 'This user owns the main store and cannot be suspended.');
        }

        if ($user->status === 'suspended') {
            return back()->with('warning', 'User is already suspended.');
        }

        $oldStatus = $user->status;
        $user->update(['status' => 'suspended']);

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new BusinessSuspended($user, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('user_suspended_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $this->log($request, 'user_suspended', $user, "Suspended {$user->name}. Reason: {$data['reason']}", ['status' => $oldStatus], ['status' => 'suspended', 'reason' => $data['reason']]);

        return back()->with('success', 'User suspended.');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $data = $request->validate(['reason' => 'required|string|max:2000']);

        $oldStatus = $user->status;
        $user->update(['status' => 'active']);

        $kycApplication = $user->kycApplication;
        if ($kycApplication && $kycApplication->status !== 'approved') {
            $kycApplication->update([
                'status' => 'approved',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'reviewer_notes' => 'Auto-approved during user activation: '.$data['reason'],
            ]);
        }

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new BusinessReactivated($user, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('user_reactivated_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        $this->log($request, 'user_activated', $user, "Activated {$user->name}. Reason: {$data['reason']}", ['status' => $oldStatus], ['status' => 'active', 'reason' => $data['reason']]);

        return back()->with('success', 'User activated.');
    }

    public function verify(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $user->is_verified = true;
        $user->email_verified_at = now();
        $user->save();

        $this->log($request, 'user_verified', $user, "Verified {$user->name}");

        return back()->with('success', 'User marked as verified.');
    }

    public function unverify(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $user->is_verified = false;
        $user->email_verified_at = null;
        $user->save();

        $this->log($request, 'user_unverified', $user, "Removed verification for {$user->name}");

        return back()->with('success', 'User verification removed.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $temporaryPassword = Str::upper(Str::random(4)).'-'.Str::lower(Str::random(4)).'-'.random_int(1000, 9999);

        $user->update([
            'password' => $temporaryPassword,
            'force_password_change' => true,
        ]);

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new UserPasswordResetMail($user, $temporaryPassword));
            }
        } catch (\Throwable $e) {
            Log::error('user_password_reset_mail_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return back()->with('warning', 'Password reset, but the email could not be queued. Temporary password: '.$temporaryPassword);
        }

        $this->log($request, 'user_password_reset', $user, "Reset password for {$user->name}");

        return back()->with('success', 'Temporary password generated and emailed to the user.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        if ($this->ownsMainStore($user)) {
            return back()->with('error', 'This user owns the main store and cannot be deleted.');
        }

        $storeIds = $user->stores()->where('status', '!=', 'deleted')->pluck('id')->toArray();

        if (! empty($storeIds)) {
            if (Order::whereIn('store_id', $storeIds)->where('status', '!=', OrderStatus::COMPLETED->value)->exists()) {
                return back()->with('error', "Deletion rejected: {$user->name} has stores with incomplete orders.");
            }

            if (Transaction::whereHas('order', fn ($q) => $q->whereIn('store_id', $storeIds))
                ->where('status', '!=', TransactionStatus::CONFIRMED->value)
                ->exists()) {
                return back()->with('error', "Deletion rejected: {$user->name} has stores with incomplete transactions.");
            }
        }

        $user->update(['status' => 'deleted']);

        $this->log($request, 'user_deleted', $user, "Deleted user {$user->name}");

        return redirect()->route('admin.users.index')->with('success', "User '{$user->name}' has been deleted.");
    }

    public function restore(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $user->update(['status' => 'active']);

        $this->log($request, 'user_restored', $user, "Restored user {$user->name}");

        return back()->with('success', 'User restored.');
    }

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        $this->ensureManaged($user);

        $admin = $request->user();

        if ($user->is($admin)) {
            return back()->with('error', 'You cannot impersonate yourself.');
        }

        if ($user->isAdmin()) {
            return back()->with('error', 'Admin accounts cannot be impersonated.');
        }

        if ($user->status === 'deleted') {
            return back()->with('error', 'Deleted users cannot be impersonated.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonator_id', $admin->id);
        $request->session()->put('impersonated_user_id', $user->id);

        $this->log($request, 'user_impersonated', $user, "{$admin->name} started impersonating {$user->name}");

        return redirect()->route('management.dashboard')
            ->with('info', "You are now viewing as {$user->name}.");
    }

    public function stopImpersonate(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonatedId = $request->session()->get('impersonated_user_id');

        if (! $impersonatorId) {
            return redirect()->route('admin.login');
        }

        $admin = User::find($impersonatorId);
        $impersonated = $impersonatedId ? User::find($impersonatedId) : null;

        if (! $admin) {
            $request->session()->forget(['impersonator_id', 'impersonated_user_id']);
            Auth::logout();

            return redirect()->route('admin.login')->with('error', 'Impersonation session could not be restored.');
        }

        $request->session()->forget(['impersonator_id', 'impersonated_user_id']);
        Auth::login($admin);
        $request->session()->regenerate();

        if ($impersonated) {
            ActivityLog::create([
                'user_id' => $admin->id,
                'business_id' => $impersonated->business_id,
                'action' => 'user_impersonation_stopped',
                'subject_type' => User::class,
                'subject_id' => $impersonated->id,
                'description' => "{$admin->name} stopped impersonating {$impersonated->name}",
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return $impersonated
            ? redirect()->route('admin.users.show', $impersonated)->with('success', 'Returned to admin.')
            : redirect()->route('admin.users.index')->with('success', 'Returned to admin.');
    }

    private function ensureManaged(User $user): void
    {
        if (! in_array($user->role, self::USER_ROLES, true)) {
            abort(404);
        }
    }

    private function ownsMainStore(User $user): bool
    {
        $mainStoreId = Setting::value('main_store_id');

        return $mainStoreId && $user->stores()->where('id', $mainStoreId)->exists();
    }

    private function log(Request $request, string $action, User $subject, string $description, ?array $old = null, ?array $new = null): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'business_id' => $subject->business_id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $subject->id,
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
