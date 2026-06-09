@extends('management.layout')
@section('subtitle', 'Create Category')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Create Category" subtitle="Organize your products" />

<div>
    <x-management.card>
        <form action="{{ route('management.categories.store') }}" method="POST" class="space-y-5">
            @csrf
            @if($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-disc pl-4 space-y-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
            @endif
            <x-management.form-input name="name" label="Category Name" placeholder="Electronics" required :error="$errors->first('name')" />
            <x-management.form-input name="store_id" label="Store" type="select" required :error="$errors->first('store_id')">
                <option value="">Select a store</option>
                @foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id') == $s->id)>{{ $s->name }}</option>@endforeach
            </x-management.form-input>
            <x-management.form-input name="description" label="Description" type="textarea" />
            <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">Create Category</button>
                <a href="{{ route('management.categories.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
            </div>
        </form>
    </x-management.card>
</div>
@endsection
