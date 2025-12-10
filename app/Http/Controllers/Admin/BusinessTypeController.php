<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BusinessTypeController extends Controller
{
    public function index()
    {
        Log::info('business_types_viewed', ['user_id' => auth()->id()]);
        $types = BusinessType::orderBy('name')->paginate(20);
        return view('admin.business_types.index', compact('types'));
    }

    public function create()
    {
        Log::info('business_type_create_viewed', ['user_id' => auth()->id()]);
        return view('admin.business_types.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:business_types,name',
        ]);
        $type = BusinessType::create($data);
        Log::info('business_type_created', ['user_id' => auth()->id(), 'id' => $type->id]);
        return redirect()->route('admin.business-types.index')->with('success', 'Business type created');
    }

    public function edit(BusinessType $businessType)
    {
        Log::info('business_type_edit_viewed', ['user_id' => auth()->id(), 'id' => $businessType->id]);
        return view('admin.business_types.edit', ['type' => $businessType]);
    }

    public function update(Request $request, BusinessType $businessType)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:business_types,name,'.$businessType->id,
        ]);
        $businessType->update($data);
        Log::info('business_type_updated', ['user_id' => auth()->id(), 'id' => $businessType->id]);
        return redirect()->route('admin.business-types.index')->with('success', 'Business type updated');
    }

    public function destroy(BusinessType $businessType)
    {
        Log::info('business_type_delete_requested', ['user_id' => auth()->id(), 'id' => $businessType->id]);
        $businessType->delete();
        return redirect()->route('admin.business-types.index')->with('success', 'Business type deleted');
    }
}
