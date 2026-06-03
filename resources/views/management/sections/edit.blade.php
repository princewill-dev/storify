@extends('management.layout')
@section('subtitle', $warehouse->name . ' — Edit Section')

@section('content')
<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('management.sections.show', [$warehouse, $section]) }}" class="text-slate-400 hover:text-slate-600">
        <i class="fi fi-rr-arrow-left"></i>
    </a>
    <h2 class="text-lg font-semibold text-slate-900">Edit: {{ $section->name }}</h2>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2">
        <x-management.card header="Section Details">
            <form method="POST" action="{{ route('management.sections.update', [$warehouse, $section]) }}" class="space-y-4">
                @csrf @method('PUT')
                <x-management.form-input name="name" label="Section Name" :value="old('name', $section->name)" required :error="$errors->first('name')" />
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Description</label>
                    <textarea name="description" rows="3" class="block w-full rounded-lg border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 text-sm">{{ old('description', $section->description) }}</textarea>
                    @error('description')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-center gap-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" {{ old('is_active', $section->isActive()) ? 'checked' : '' }}>
                    <span class="text-sm text-slate-700">Active</span>
                </div>
                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
                    <a href="{{ route('management.sections.show', [$warehouse, $section]) }}" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Cancel</a>
                </div>
            </form>
        </x-management.card>
    </div>

    <div>
        <x-management.card header="Section Info">
            <div class="space-y-3">
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Code</span>
                    <span class="text-sm font-medium text-slate-700 font-mono">{{ $section->section_code }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Warehouse</span>
                    <span class="text-sm font-medium text-slate-700">{{ $warehouse->name }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm text-slate-500">Created</span>
                    <span class="text-sm font-medium text-slate-700">{{ $section->created_at->format('d M Y') }}</span>
                </div>
            </div>
        </x-management.card>
    </div>

</div>
@endsection
