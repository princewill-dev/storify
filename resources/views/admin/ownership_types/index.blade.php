@extends('admin.layout')
@section('subtitle', 'Ownership Types')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Ownership Types</h2>
    <a href="{{ route('admin.ownership-types.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">New type</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="text-left py-3 px-4 font-medium text-slate-600">Name</th>
                <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($types as $t)
                <tr>
                    <td class="py-3 px-4 text-slate-700">{{ $t->name }}</td>
                    <td class="py-3 px-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.ownership-types.edit', $t) }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Edit</a>
                            <button type="button" onclick="openModal('deleteOwnershipType{{ $t->id }}')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Delete</button>
                            <x-admin.confirm-modal id="deleteOwnershipType{{ $t->id }}" title="Delete Ownership Type" message="Delete this type?" action="{{ route('admin.ownership-types.destroy', $t) }}" method="DELETE" />
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="2" class="py-12 text-center text-slate-400">No items</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="px-6 py-4 border-t border-slate-100">
        {{ $types->links() }}
    </div>
</div>
@endsection
