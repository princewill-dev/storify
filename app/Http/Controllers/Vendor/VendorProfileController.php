<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class VendorProfileController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $request->user('vendor');
        $vendor->load(['ownershipType', 'businessType']);
        
        return view('vendors.profile.index', compact('vendor'));
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password:vendor'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user('vendor')->update([
            'password' => Hash::make($request->password),
        ]);

        return back()->with('success', 'Password updated successfully.');
    }
}
