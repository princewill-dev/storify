@extends('vendors.layout')
@section('title', 'Create Page Styling')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-xl-8 offset-xl-2">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Create Page Styling</h4>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.styling.store') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="page_label" class="form-label">Page Label <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('page_label') is-invalid @enderror" id="page_label" name="page_label" value="{{ old('page_label') }}" placeholder="e.g., Product Details Page" required>
                            <small class="text-muted">Human-readable name for the page</small>
                            @error('page_label')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="page_name" class="form-label">Page Name (Identifier) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('page_name') is-invalid @enderror" id="page_name" name="page_name" value="{{ old('page_name') }}" placeholder="e.g., product_details" required>
                            <small class="text-muted">Unique identifier (use lowercase with underscores, e.g., product_details, home, checkout)</small>
                            @error('page_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="background_color" class="form-label">Background Color</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color @error('background_color') is-invalid @enderror" id="background_color_picker" value="{{ old('background_color', '#ffffff') }}" style="max-width: 60px;">
                                <input type="text" class="form-control @error('background_color') is-invalid @enderror" id="background_color" name="background_color" value="{{ old('background_color') }}" placeholder="#ffffff">
                            </div>
                            <small class="text-muted">Pick a color or enter hex code (e.g., #f5f5f5)</small>
                            @error('background_color')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="custom_css" class="form-label">Custom CSS (Optional)</label>
                            <textarea class="form-control @error('custom_css') is-invalid @enderror" id="custom_css" name="custom_css" rows="6" placeholder="Enter additional CSS rules...">{{ old('custom_css') }}</textarea>
                            <small class="text-muted">Add any custom CSS for this page (advanced users only)</small>
                            @error('custom_css')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                            <small class="text-muted">Enable or disable this styling</small>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('admin.styling.index') }}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Create Styling</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Sync color picker with text input
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
