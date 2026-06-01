<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $request->user();

        return view('management.profile.index', compact('vendor'));
    }

    public function update(Request $request)
    {
        $vendor = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'remove_photo' => 'nullable|boolean',
        ]);

        $data = [
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
        ];

        if ($request->hasFile('photo')) {
            if ($vendor->photo_path) {
                Storage::disk('public')->delete($vendor->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('photos', 'public');
        } elseif ($request->boolean('remove_photo') && $vendor->photo_path) {
            Storage::disk('public')->delete($vendor->photo_path);
            $data['photo_path'] = null;
        }

        $vendor->update($data);

        return back()->with('success', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $vendor = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $vendor->update([
            'password' => Hash::make($request->password),
        ]);

        return back()->with('password_success', 'Password updated successfully.');
    }
}
