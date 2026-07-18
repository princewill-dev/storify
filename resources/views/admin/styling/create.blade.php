@extends('admin.layout')
@section('title', 'Create Page Styling')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-lg font-bold text-slate-900">Create Page Styling</h2>
        <a href="{{ route('admin.styling.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h2 class="text-base font-semibold text-slate-900">Create Page Styling</h2>
        </div>
        <div class="p-6">
            <form action="{{ route('admin.styling.store') }}" method="POST">
                @csrf

                <div class="space-y-4">
                    <div>
                        <label for="page_label" class="block text-sm font-medium text-slate-700 mb-1">Page Label <span class="text-red-500">*</span></label>
                        <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('page_label') border-red-500 @enderror" id="page_label" name="page_label" value="{{ old('page_label') }}" placeholder="e.g., Product Details Page" required>
                        <p class="text-xs text-slate-400 mt-1">Human-readable name for the page</p>
                        @error('page_label')
                            <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label for="page_name" class="block text-sm font-medium text-slate-700 mb-1">Page Name (Identifier) <span class="text-red-500">*</span></label>
                        <input type="text" class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('page_name') border-red-500 @enderror" id="page_name" name="page_name" value="{{ old('page_name') }}" placeholder="e.g., product_details" required>
                        <p class="text-xs text-slate-400 mt-1">Unique identifier (use lowercase with underscores, e.g., product_details, home, checkout)</p>
                        @error('page_name')
                            <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label for="background_color" class="block text-sm font-medium text-slate-700 mb-1">Background Color</label>
                        <div class="flex gap-2">
                            <input type="color" class="h-10 w-10 rounded-lg border border-slate-300 cursor-pointer @error('background_color') border-red-500 @enderror" id="background_color_picker" value="{{ old('background_color', '#ffffff') }}">
                            <input type="text" class="flex-1 rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('background_color') border-red-500 @enderror" id="background_color" name="background_color" value="{{ old('background_color') }}" placeholder="#ffffff">
                        </div>
                        <p class="text-xs text-slate-400 mt-1">Pick a color or enter hex code (e.g., #f5f5f5)</p>
                        @error('background_color')
                            <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label for="custom_css" class="block text-sm font-medium text-slate-700 mb-1">Custom CSS (Optional)</label>
                        <textarea class="w-full rounded-lg border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 font-mono @error('custom_css') border-red-500 @enderror" id="custom_css" name="custom_css" rows="6" placeholder="Enter additional CSS rules...">{{ old('custom_css') }}</textarea>
                        <p class="text-xs text-slate-400 mt-1">Add any custom CSS for this page (advanced users only)</p>
                        @error('custom_css')
                            <div class="text-sm text-red-500 mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="flex items-center gap-3">
                        <input class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                        <label class="text-sm text-slate-700" for="is_active">Active</label>
                        <span class="text-xs text-slate-400">Enable or disable this styling</span>
                    </div>
                </div>

                <div class="flex items-center justify-between mt-6 pt-4 border-t border-slate-100">
                    <a href="{{ route('admin.styling.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancel</a>
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create Styling</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('background_color_picker').addEventListener('input', function(e) {
        document.getElementById('background_color').value = e.target.value;
    });

    document.getElementById('background_color').addEventListener('input', function(e) {
        const value = e.target.value;
        if (value.match(/^#[0-9A-Fa-f]{6}$/)) {
            document.getElementById('background_color_picker').value = value;
        }
    });
</script>
@endsection
