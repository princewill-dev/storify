@extends('management.layout')
@section('subtitle', 'Create Category')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Create Category" subtitle="Organize your products" />

<div>
    <x-management.card>
        <form action="{{ route('management.categories.store') }}" method="POST" class="space-y-5">
            @csrf
            <x-management.form-input name="name" label="Category Name" placeholder="Electronics" required />
            <x-management.form-input name="description" label="Description" type="textarea" />
            <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">Create Category</button>
                <a href="{{ route('management.categories.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
            </div>
        </form>
    </x-management.card>
</div>
@endsection
