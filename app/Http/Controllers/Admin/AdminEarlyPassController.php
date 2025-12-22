<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EarlyPass;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminEarlyPassController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $passes = EarlyPass::withCount('usages')->latest()->paginate(20);

        return view('admin.Earlyaccess.index', compact('passes'));
    }

    /**
     * Show the details of the resource.
     */
    public function show(EarlyPass $earlyPass)
    {
        $earlyPass->load(['usages.vendor', 'usages.store']);
        
        return view('admin.Earlyaccess.show', compact('earlyPass'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'unique:early_passes,code', 'min:3', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ]);

        EarlyPass::create([
            'code' => strtoupper($request->code),
            'description' => $request->description,
            'max_uses' => $request->max_uses,
            'is_active' => true,
        ]);

        return redirect()->route('admin.early-access.index')
            ->with('success', 'Early access pass created successfully.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, EarlyPass $earlyPass)
    {
        $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ]);

        $earlyPass->update([
            'description' => $request->description,
            'max_uses' => $request->max_uses,
        ]);

        return redirect()->route('admin.early-access.index')
            ->with('success', 'Pass updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EarlyPass $earlyPass)
    {
        if ($earlyPass->usages()->exists()) {
            return back()->with('error', 'Cannot delete a used pass. Deactivate it instead.');
        }

        $earlyPass->delete();

        return redirect()->route('admin.early-access.index')
            ->with('success', 'Pass deleted successfully.');
    }

    /**
     * Toggle the active status of the pass.
     */
    public function toggleStatus(EarlyPass $earlyPass)
    {
        $earlyPass->update([
            'is_active' => !$earlyPass->is_active,
        ]);

        $status = $earlyPass->is_active ? 'activated' : 'deactivated';

        return redirect()->route('admin.early-access.index')
            ->with('success', "Pass has been {$status}.");
    }
}
