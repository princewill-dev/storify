<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VatRequest;
use App\Models\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VatController extends Controller
{
    public function index()
    {
        $vats = Vat::orderByDesc('active')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(20);
        return view('admin.VAT.index', compact('vats'));
    }

    public function store(VatRequest $request)
    {
        $data = $request->validated();
        // Business rule: only one VAT can be active. Any new VAT becomes the current one.
        $data['active'] = true;
        if (empty($data['effective_at'])) {
            $data['effective_at'] = now();
        }
        \DB::transaction(function() use (&$vat, $data) {
            Vat::query()->update(['active' => false]);
            $vat = Vat::create($data);
        });
        Log::info('vat_created', ['user_id' => auth()->id(), 'vat_id' => $vat->id, 'pct' => $vat->percentage]);
        return redirect()->route('admin.vats.index')->with('success', 'VAT created');
    }

    public function edit(Vat $vat)
    {
        $vats = Vat::orderByDesc('active')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(20);
        return view('admin.VAT.index', compact('vats', 'vat'));
    }

    public function update(VatRequest $request, Vat $vat)
    {
        $data = $request->validated();
        // If this VAT is marked active (or if no flag provided but we want it active), enforce single-active rule
        if (array_key_exists('active', $data) ? (bool)$data['active'] : false) {
            \DB::transaction(function() use ($vat, $data) {
                Vat::where('id', '!=', $vat->id)->update(['active' => false]);
                $vat->update($data + ['active' => true]);
            });
        } else {
            $vat->update($data);
        }
        Log::info('vat_updated', ['user_id' => auth()->id(), 'vat_id' => $vat->id]);
        return redirect()->route('admin.vats.index')->with('success', 'VAT updated');
    }

    public function destroy(Vat $vat)
    {
        $vat->delete();
        Log::info('vat_deleted', ['user_id' => auth()->id(), 'vat_id' => $vat->id]);
        return redirect()->route('admin.vats.index')->with('success', 'VAT deleted');
    }

    public function toggle(Vat $vat)
    {
        // Business rule: VAT cannot be fully disabled; create a new 0% VAT record instead
        $zero = Vat::create([
            'percentage' => 0.00,
            'active' => true,
            'effective_at' => now(),
        ]);
        Log::info('vat_zero_created', ['user_id' => auth()->id(), 'old_vat_id' => $vat->id, 'new_vat_id' => $zero->id]);
        return redirect()->route('admin.vats.index')->with('success', '0% VAT created');
    }
}
