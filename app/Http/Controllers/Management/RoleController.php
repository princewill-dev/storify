<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        $roles = Role::where('business_id', $vendor->business_id)
            ->withCount('users')
            ->with('permissions')
            ->latest()
            ->get();

        return view('management.roles.index', compact('vendor', 'roles'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        $availablePermissions = \Spatie\Permission\Models\Permission::all()->groupBy(function ($p) {
            return explode(' ', $p->name, 2)[0];
        });

        return view('management.roles.create', compact('vendor', 'availablePermissions'));
    }

    public function store(Request $request): RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,NULL,id,business_id,' . $vendor->business_id,
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'business_id' => $vendor->business_id,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($validated['permissions']);

        return redirect()->route('management.roles.index')
            ->with('success', 'Role created successfully.');
    }

    public function edit(Request $request, Role $role): View|RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        if ($role->business_id !== $vendor->business_id) {
            abort(404);
        }

        $availablePermissions = \Spatie\Permission\Models\Permission::all()->groupBy(function ($p) {
            return explode(' ', $p->name, 2)[0];
        });

        return view('management.roles.edit', compact('vendor', 'role', 'availablePermissions'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        if ($role->business_id !== $vendor->business_id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id . ',id,business_id,' . $vendor->business_id,
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role->update(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions']);

        return redirect()->route('management.roles.index')
            ->with('success', 'Role updated successfully.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $vendor = $request->user();
        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        if ($role->business_id !== $vendor->business_id) {
            abort(404);
        }

        $protectedRoles = ['Super Admin', 'Developer', 'Store Associate'];
        if (in_array($role->name, $protectedRoles)) {
            return back()->with('error', 'Cannot delete a protected system role.');
        }

        if ($role->users()->count() > 0) {
            return back()->with('error', 'Cannot delete a role assigned to users. Remove the role from all users first.');
        }

        $role->delete();

        return redirect()->route('management.roles.index')
            ->with('success', 'Role deleted successfully.');
    }
}
