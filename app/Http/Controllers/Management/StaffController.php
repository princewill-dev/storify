<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class StaffController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $query = User::where('business_id', $user->business_id)
            ->whereIn('role', ['staff', 'business_owner'])
            ->where('status', '!=', 'deleted')
            ->with('roles', 'assignedStores', 'assignedWarehouses');

        $store = null;
        if ($request->filled('store_id')) {
            $store = $user->accessibleStores()->where('store_id', $request->query('store_id'))->first();
            if ($store) {
                $query->whereHas('assignedStores', fn($q) => $q->where('assignmentable_id', $store->id));
            }
        }

        $staff = $query->latest()->get();

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Staff']];
        return view('management.staff.index', compact('user', 'staff', 'store', 'breadcrumbs'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $roles = Role::where('business_id', $user->business_id)->get();
        $stores = $user->accessibleStores()->where('status', '!=', 'deleted')->get();
        $warehouses = $user->warehouses()->where('status', '!=', 'deleted')->get();

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Staff', 'url' => route('management.staff.index')], ['label' => 'Create']];
        return view('management.staff.create', compact('user', 'roles', 'stores', 'warehouses', 'breadcrumbs'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|unique:customers,email',
            'phone' => 'nullable|string|max:20',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|string|exists:roles,name',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'warehouse_ids' => 'nullable|array',
            'warehouse_ids.*' => 'exists:warehouses,id',
            'documents' => 'nullable|array|max:10',
            'documents.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|max:5120',
            'document_tags' => 'nullable|array',
            'document_tags.*' => 'nullable|string|max:100',
        ]);

        $staffData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => 'staff',
            'business_id' => $user->business_id,
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
            'status' => 'invited',
            'is_verified' => true,
            'email_verified_at' => now(),
        ];

        if ($request->hasFile('photo')) {
            $staffData['photo_path'] = $request->file('photo')->store('photos', 'public');
        }

        $plainPassword = null;
        if (!empty($validated['password'])) {
            $staffData['password'] = $validated['password'];
            $staffData['force_password_change'] = true;
            $plainPassword = $validated['password'];
        } else {
            $staffData['password'] = bcrypt(Str::random(32));
            $staffData['force_password_change'] = false;
        }

        $staffUser = User::create($staffData);

        setPermissionsTeamId($staffUser->business_id);
        $staffUser->assignRole($validated['role']);

        if ($request->hasFile('documents')) {
            $tags = $request->input('document_tags', []);
            foreach ($request->file('documents') as $index => $file) {
                $path = $file->store('staff-documents', 'public');
                $staffUser->documents()->create([
                    'file_name' => $file->hashName(),
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'tag' => $tags[$index] ?? null,
                ]);
            }
        }

        if (!empty($validated['store_ids'])) {
            $staffUser->assignedStores()->sync($validated['store_ids']);
        }

        if (!empty($validated['warehouse_ids'])) {
            $staffUser->assignedWarehouses()->sync($validated['warehouse_ids']);
        }

        if (class_exists(\App\Mail\StaffInvitationMail::class)) {
            \Mail::to($staffUser->email)->queue(new \App\Mail\StaffInvitationMail($staffUser, $plainPassword));
        }

        return redirect()->route('management.staff.index')
            ->with('success', 'Staff member invited successfully. They will receive an email with setup instructions.');
    }

    public function show(Request $request, User $staff): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || ($staff->role !== 'staff' && !$staff->isBusinessOwner()) || $staff->business_id !== $user->business_id) {
            abort(403);
        }

        $staff->load('roles', 'assignedStores', 'assignedWarehouses');
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Staff', 'url' => route('management.staff.index')], ['label' => $staff->name]];
        return view('management.staff.show', compact('user', 'staff', 'breadcrumbs'));
    }

    public function edit(Request $request, User $staff): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || $staff->role !== 'staff' || $staff->business_id !== $user->business_id) {
            abort(403);
        }

        $roles = Role::where('business_id', $user->business_id)->with('permissions')->get();
        $staff->load('roles', 'documents');
        $assignedRoles = $staff->getRoleNames()->toArray();

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Staff', 'url' => route('management.staff.index')], ['label' => $staff->name, 'url' => route('management.staff.show', $staff)], ['label' => 'Edit']];
        return view('management.staff.edit', compact('user', 'staff', 'roles', 'assignedRoles', 'breadcrumbs'));
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $staff->role !== 'staff' || $staff->business_id !== $user->business_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'remove_photo' => 'nullable|boolean',
            'role' => 'nullable|string|exists:roles,name',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
            'documents' => 'nullable|array|max:10',
            'documents.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|max:5120',
            'document_tags' => 'nullable|array',
            'document_tags.*' => 'nullable|string|max:100',
            'delete_document_ids' => 'nullable|array',
            'delete_document_ids.*' => 'exists:staff_documents,id',
        ]);

        Log::info('staff.update.start', [
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
            'old_roles' => $staff->getRoleNames()->toArray(),
            'new_role' => $validated['role'] ?? null,
            'new_roles' => $validated['roles'] ?? null,
        ]);

        $data = [
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
        ];

        if ($request->hasFile('photo')) {
            if ($staff->photo_path) {
                Storage::disk('public')->delete($staff->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('photos', 'public');
        } elseif ($request->boolean('remove_photo') && $staff->photo_path) {
            Storage::disk('public')->delete($staff->photo_path);
            $data['photo_path'] = null;
        }

        $staff->update($data);

        setPermissionsTeamId($user->business_id);
        $rolesToSync = $validated['roles'] ?? [$validated['role'] ?? null];
        $rolesToSync = array_filter($rolesToSync);
        $staff->syncRoles($rolesToSync);

        Log::info('staff.update.role_changed', [
            'staff_id' => $staff->id,
            'new_roles' => $staff->fresh()->getRoleNames()->toArray(),
        ]);

        if ($request->has('delete_document_ids')) {
            $toDelete = $staff->documents()->whereIn('id', $request->delete_document_ids)->get();
            foreach ($toDelete as $doc) {
                Storage::disk('public')->delete($doc->file_path);
                $doc->delete();
            }
        }

        if ($request->hasFile('documents')) {
            $tags = $request->input('document_tags', []);
            foreach ($request->file('documents') as $index => $file) {
                $path = $file->store('staff-documents', 'public');
                $staff->documents()->create([
                    'file_name' => $file->hashName(),
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'tag' => $tags[$index] ?? null,
                ]);
            }
        }

        return redirect()->route('management.staff.edit', $staff)
            ->with('success', 'Staff member updated successfully.');
    }

    public function resendInvite(Request $request, User $staff): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $staff->role !== 'staff' || $staff->business_id !== $user->business_id) {
            abort(403);
        }

        if ($staff->status !== 'invited') {
            return back()->with('error', 'This staff member has already accepted the invitation.');
        }

        $staff->update([
            'invitation_token' => Str::random(64),
            'invited_at' => now(),
        ]);

        if (class_exists(\App\Mail\StaffInvitationMail::class)) {
            \Mail::to($staff->email)->queue(new \App\Mail\StaffInvitationMail($staff));
        }

        return back()->with('success', 'Invitation resent successfully.');
    }

    public function suspend(Request $request, User $staff): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $staff->role !== 'staff' || $staff->business_id !== $user->business_id) {
            abort(403);
        }

        $staff->update(['status' => 'suspended']);

        return back()->with('success', 'Staff member suspended.');
    }

    public function activate(Request $request, User $staff): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $staff->role !== 'staff' || $staff->business_id !== $user->business_id) {
            abort(403);
        }

        $staff->update(['status' => 'active']);

        return back()->with('success', 'Staff member activated.');
    }

    public function destroy(Request $request, User $staff): RedirectResponse
    {
        $user = $request->user();
        if (!$user || $staff->role !== 'staff' || $staff->business_id !== $user->business_id) {
            abort(403);
        }

        $staff->update(['status' => 'deleted']);

        return redirect()->route('management.staff.index')
            ->with('success', 'Staff member removed.');
    }
}
