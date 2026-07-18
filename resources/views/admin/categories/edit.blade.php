@extends('admin.layout')
@section('subtitle', 'Edit Categories')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Edit category</h2>
  <a href="{{ route('admin.categories.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Back</a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
  <form method="post" action="{{ route('admin.categories.update', $category) }}" class="space-y-4">
    @csrf
    @method('PUT')
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Store</label>
        <select name="store_id" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          @foreach($stores as $s)
            <option value="{{ $s->id }}" @selected(old('store_id', $category->store_id)==$s->id)>{{ $s->name }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
        <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required>
          <option value="active" @selected(old('status', $category->status)=='active')>active</option>
          <option value="inactive" @selected(old('status', $category->status)=='inactive')>inactive</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Name</label>
        <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500" value="{{ old('name', $category->name) }}" required>
      </div>
    </div>
    <div class="flex items-center gap-2 pt-4 border-t border-slate-100">
      <button class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800" type="submit">Save changes</button>
      <a class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" href="{{ route('admin.categories.index') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection
