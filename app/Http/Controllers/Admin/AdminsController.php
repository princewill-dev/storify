<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminInvitationMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class AdminsController extends Controller
{
    public function index(): View
    {
        $admins = User::whereIn('role', ['superadmin', 'admin'])
            ->with('roles')
            ->orderBy('created_at', 'desc')
            ->get();

        $roles = Role::whereNull('business_id')
            ->where('guard_name', 'web')
            ->where('name', '!=', 'Super Admin')
            ->orderBy('name')
            ->get();

        return view('admin.admins.index', compact('admins', 'roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users,email|unique:customers,email',
            'role' => 'required|string|exists:roles,name',
        ]);

        $role = Role::where('name', $validated['role'])
            ->whereNull('business_id')
            ->first();

        if (! $role) {
            return back()->withInput()->with('error', 'Please select a valid admin role.');
        }

        $admin = User::create([
            'name' => '',
            'email' => $validated['email'],
            'role' => 'admin',
            'business_id' => null,
            'status' => 'invited',
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
            'password' => bcrypt(Str::random(32)),
            'force_password_change' => true,
        ]);

        setPermissionsTeamId(null);
        $admin->assignRole($role);

        try {
            Mail::to($admin->email)->queue(new AdminInvitationMail($admin));
        } catch (\Throwable $e) {
            Log::error('admin.invitation.mail_failed', [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('admin.invited', [
            'invited_by' => $request->user()->id,
            'admin_id' => $admin->id,
            'email' => $admin->email,
            'role' => $role->name,
        ]);

        return redirect()->route('admin.admins.index')
            ->with('success', "Invitation sent to {$admin->email} as {$role->name}.");
    }

    public function resend(Request $request, User $admin): RedirectResponse
    {
        if (! in_array($admin->role, ['admin', 'superadmin'], true)) {
            abort(404);
        }

        if ($admin->status !== 'invited') {
            return back()->with('warning', 'This admin has already accepted their invitation.');
        }

        $admin->update([
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
        ]);

        try {
            Mail::to($admin->email)->queue(new AdminInvitationMail($admin));
        } catch (\Throwable $e) {
            Log::error('admin.invitation.resend_mail_failed', [
                'user_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Invitation resent.');
    }

    public function update(Request $request, User $admin): RedirectResponse
    {
        if ($admin->id === $request->user()->id) {
            return back()->with('error', 'You cannot change your own role.');
        }

        if ($admin->role === 'superadmin') {
            return back()->with('error', 'The superadmin role cannot be changed.');
        }

        $validated = $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        $role = Role::where('name', $validated['role'])
            ->whereNull('business_id')
            ->first();

        if (! $role) {
            return back()->with('error', 'Please select a valid admin role.');
        }

        setPermissionsTeamId(null);
        $admin->syncRoles([$role]);

        Log::info('admin.role_changed', [
            'changed_by' => $request->user()->id,
            'admin_id' => $admin->id,
            'role' => $role->name,
        ]);

        return back()->with('success', "{$admin->name}'s role updated to {$role->name}.");
    }

    public function destroy(Request $request, User $admin): RedirectResponse
    {
        if ($admin->id === $request->user()->id) {
            return back()->with('error', 'You cannot remove your own account.');
        }

        if ($admin->role === 'superadmin') {
            return back()->with('error', 'The superadmin account cannot be removed.');
        }

        $email = $admin->email;
        $admin->delete();

        Log::info('admin.removed', [
            'removed_by' => $request->user()->id,
            'admin_id' => $admin->id,
            'email' => $email,
        ]);

        return redirect()->route('admin.admins.index')
            ->with('success', "{$email} has been removed.");
    }
}
