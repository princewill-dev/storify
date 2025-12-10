<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OwnershipType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OwnershipTypeController extends Controller
{
    public function index()
    {
        Log::info('ownership_types_viewed', ['user_id' => auth()->id()]);
        $types = OwnershipType::orderBy('name')->paginate(20);
        return view('admin.ownership_types.index', compact('types'));
    }

    public function create()
    {
        Log::info('ownership_type_create_viewed', ['user_id' => auth()->id()]);
        return view('admin.ownership_types.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:ownership_types,name',
        ]);
        $type = OwnershipType::create($data);
        Log::info('ownership_type_created', ['user_id' => auth()->id(), 'id' => $type->id]);
        return redirect()->route('admin.ownership-types.index')->with('success', 'Ownership type created');
    }

    public function edit(OwnershipType $ownershipType)
    {
        Log::info('ownership_type_edit_viewed', ['user_id' => auth()->id(), 'id' => $ownershipType->id]);
        return view('admin.ownership_types.edit', ['type' => $ownershipType]);
    }

    public function update(Request $request, OwnershipType $ownershipType)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:ownership_types,name,'.$ownershipType->id,
        ]);
        $ownershipType->update($data);
        Log::info('ownership_type_updated', ['user_id' => auth()->id(), 'id' => $ownershipType->id]);
        return redirect()->route('admin.ownership-types.index')->with('success', 'Ownership type updated');
    }

    public function destroy(OwnershipType $ownershipType)
    {
        Log::info('ownership_type_delete_requested', ['user_id' => auth()->id(), 'id' => $ownershipType->id]);
        $ownershipType->delete();
        return redirect()->route('admin.ownership-types.index')->with('success', 'Ownership type deleted');
    }
}
