@extends('admin.layout')
@section('subtitle', 'New Ownership Type')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Create ownership type</h2>
    <a href="{{ route('admin.ownership-types.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="p-6">
        <form method="post" action="{{ route('admin.ownership-types.store') }}">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                    <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('name') }}" required>
                </div>
            </div>
            <div class="flex items-center gap-3 mt-6 pt-4 border-t border-slate-100">
                <button class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800" type="submit">Create</button>
                <a class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50" href="{{ route('admin.ownership-types.index') }}">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
