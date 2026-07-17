@extends('management.layout')
@section('subtitle', 'Sections')

@section('content')
<div class="flex items-center gap-3 mb-1">
    <a href="{{ route('management.warehouses.show', $warehouse) }}" class="text-slate-400 hover:text-slate-600">
        <i class="fi fi-rr-arrow-left"></i>
    </a>
    <div>
        <h2 class="text-lg font-semibold text-slate-900">{{ $warehouse->name }} — Sections</h2>
        <p class="text-xs text-slate-400">Physical zones within this warehouse · {{ $sections->count() }} sections</p>
    </div>
</div>

<div class="flex items-center justify-between mb-4">
    <div></div>
    <a href="{{ route('management.sections.create', $warehouse) }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors">
        <i class="fi fi-rr-plus text-xs"></i> Add Section
    </a>
</div>

<x-management.data-table>
    <x-slot:header>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Name</th>
        <th class="px-5 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Description</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">Products</th>
        <th class="px-5 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider hidden sm:table-cell">Status</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider hidden md:table-cell">Code</th>
        <th class="px-5 py-3 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider">Actions</th>
    </x-slot:header>

    @forelse($sections as $section)
    <tr class="hover:bg-slate-50/50 transition-colors">
        <td class="px-5 py-3">
            <a href="{{ route('management.sections.show', ['warehouse' => $warehouse, 'section' => $section]) }}" class="text-sm font-medium text-blue-600 hover:text-blue-700">{{ $section->name }}</a>
        </td>
        <td class="px-5 py-3 hidden sm:table-cell"><span class="text-sm text-slate-400">{{ $section->description ?? '—' }}</span></td>
        <td class="px-5 py-3 text-center"><span class="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 text-sm font-semibold rounded-full bg-blue-50 text-blue-600">{{ $section->products_count }}</span></td>
        <td class="px-5 py-3 text-center hidden sm:table-cell"><x-management.status-badge :status="$section->isActive() ? 'active' : 'inactive'" /></td>
        <td class="px-5 py-3 text-right hidden md:table-cell"><span class="text-xs text-slate-400 font-mono">{{ $section->section_code }}</span></td>
        <td class="px-5 py-3 text-right">
            <div class="flex items-center justify-end gap-1">
                <a href="{{ route('management.sections.show', [$warehouse, $section]) }}" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg" title="View">
                    <i class="fi fi-rr-eye text-xs"></i>
                </a>
                <a href="{{ route('management.sections.edit', [$warehouse, $section]) }}" class="p-1.5 text-slate-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title="Edit">
                    <i class="fi fi-rr-edit text-xs"></i>
                </a>
                @if($section->products_count === 0)
                <button onclick="openModal('deleteSection{{ $section->id }}')" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg" title="Delete">
                    <i class="fi fi-rr-trash text-xs"></i>
                </button>
                <x-management.confirm-modal id="deleteSection{{ $section->id }}" title="Delete Section" message="Delete this section?" action="{{ route('management.sections.destroy', [$warehouse, $section]) }}" method="DELETE" />
                @endif
            </div>
        </td>
    </tr>
    @empty
    <tr>
        <td colspan="6" class="px-5 py-12">
            <x-management.empty-state icon="fi fi-rr-cube" title="No sections yet" description="Create sections to organize products within this warehouse." action-label="Add Section" action-url="{{ route('management.sections.create', $warehouse) }}" />
        </td>
    </tr>
    @endforelse
</x-management.data-table>
@endsection
