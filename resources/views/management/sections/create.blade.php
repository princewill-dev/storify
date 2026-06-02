@extends('management.layout')
@section('subtitle', 'Create Section')

@section('content')
<x-management.page-header title="Create Section" subtitle="Add a zone within {{ $warehouse->name }}" />

<div>
    <x-management.card>
        <form action="{{ route('management.sections.store', $warehouse) }}" method="POST" class="space-y-5">
            @csrf
            <x-management.form-input name="name" label="Section Name" placeholder="Aisle A" required />
            <x-management.form-input name="description" label="Description" type="textarea" placeholder="What is stored in this section?" />
            <div class="flex items-center gap-2">
                <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300 text-slate-900">
                <span class="text-sm text-slate-700">Active</span>
            </div>
            <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Create Section</button>
                <a href="{{ route('management.sections.index', $warehouse) }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
            </div>
        </form>
    </x-management.card>
</div>
@endsection
