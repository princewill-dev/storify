@extends('admin.layout')
@section('title', 'Page Styling')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Page Styling</h2>
    <a href="{{ route('admin.styling.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">
        <i class="fi fi-rr-plus"></i> New Page Style
    </a>
</div>

@if($stylings->isEmpty())
    <div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-xl p-4 text-sm">
        No page stylings configured yet. <a href="{{ route('admin.styling.create') }}" class="font-medium underline">Create one now</a>
    </div>
@else
    <div class="bg-white rounded-xl shadow-sm border border-slate-200">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">#</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Page Label</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Page Name</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Background Color</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Status</th>
                    <th class="text-right py-3 px-4 font-medium text-slate-600">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @foreach($stylings as $styling)
                <tr>
                    <td class="py-3 px-4 text-slate-700">{{ $loop->iteration }}</td>
                    <td class="py-3 px-4 font-medium text-slate-900">{{ $styling->page_label }}</td>
                    <td class="py-3 px-4"><code class="text-xs bg-slate-100 rounded px-1.5 py-0.5 text-slate-600">{{ $styling->page_name }}</code></td>
                    <td class="py-3 px-4">
                        @if($styling->background_color)
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded border border-slate-200" style="background-color: {{ $styling->background_color }};"></div>
                                <code class="text-xs bg-slate-100 rounded px-1.5 py-0.5 text-slate-600">{{ $styling->background_color }}</code>
                            </div>
                        @else
                            <span class="text-slate-400 text-sm">Not set</span>
                        @endif
                    </td>
                    <td class="py-3 px-4">
                        @if($styling->is_active)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-medium">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-600 px-2.5 py-0.5 text-xs font-medium">Inactive</span>
                        @endif
                    </td>
                    <td class="py-3 px-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.styling.edit', $styling) }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">
                                <i class="fi fi-rr-edit"></i>
                            </a>
                            <button type="button" onclick="openModal('deleteStyling{{ $styling->id }}')" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50">
                                <i class="fi fi-rr-trash"></i>
                            </button>
                            <x-admin.confirm-modal id="deleteStyling{{ $styling->id }}" title="Delete Styling" message="Are you sure you want to delete this styling?" action="{{ route('admin.styling.destroy', $styling) }}" method="DELETE" />
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
