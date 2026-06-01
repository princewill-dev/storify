@extends('management.layout')
@section('subtitle', 'Create Service')

@section('content')
<x-management.page-header title="Create Service" subtitle="Add a digital product or service offering" />

<div>
    <x-management.card>
        <form action="{{ route('management.services.store') }}" method="POST" class="space-y-5">
            @csrf
            <x-management.form-input name="name" label="Service Name" placeholder="Consulting Package" required />
            <x-management.form-input name="description" label="Description" type="textarea" placeholder="Describe the service..." />
            <x-management.form-input name="amount" label="Price (₦)" type="number" step="0.01" placeholder="0.00" required />
            <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition-colors">Create Service</button>
                <a href="{{ route('management.services.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Cancel</a>
            </div>
        </form>
    </x-management.card>
</div>
@endsection
