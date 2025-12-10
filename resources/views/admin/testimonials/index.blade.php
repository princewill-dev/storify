@extends('admin.layout')
@section('subtitle', 'Testimonials Management')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title">Testimonials Management</h4>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTestimonialModal">
                        <i class="fa fa-plus me-2"></i>Add Testimonial
                    </button>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fa fa-check-circle me-2"></i>{{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fa fa-exclamation-circle me-2"></i>{{ session('error') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 60px;">Photo</th>
                                    <th>Name</th>
                                    <th>Occupation</th>
                                    <th>Message</th>
                                    <th style="width: 80px;">Position</th>
                                    <th style="width: 100px;">Status</th>
                                    <th style="width: 150px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($testimonials as $testimonial)
                                <tr>
                                    <td>
                                        <img src="{{ $testimonial->photo }}" alt="{{ $testimonial->name }}" class="rounded-circle" style="width: 50px; height: 50px; object-fit: cover;">
                                    </td>
                                    <td>{{ $testimonial->name }}</td>
                                    <td>{{ $testimonial->occupation }}</td>
                                    <td>{{ Str::limit($testimonial->message, 100) }}</td>
                                    <td>{{ $testimonial->position }}</td>
                                    <td>
                                        @if($testimonial->status === 'active')
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editTestimonialModal{{ $testimonial->id }}">
                                                <i class="fa fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteTestimonialModal{{ $testimonial->id }}">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editTestimonialModal{{ $testimonial->id }}" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form action="{{ route('admin.testimonials.update', $testimonial) }}" method="POST" enctype="multipart/form-data">
                                                @csrf
                                                @method('PUT')
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Testimonial</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $testimonial->name) }}" required>
                                                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Occupation <span class="text-danger">*</span></label>
                                                        <input type="text" name="occupation" class="form-control @error('occupation') is-invalid @enderror" value="{{ old('occupation', $testimonial->occupation) }}" required>
                                                        @error('occupation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Message <span class="text-danger">*</span></label>
                                                        <textarea name="message" rows="4" class="form-control @error('message') is-invalid @enderror" required>{{ old('message', $testimonial->message) }}</textarea>
                                                        @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Photo</label>
                                                        <input type="file" name="photo" class="form-control @error('photo') is-invalid @enderror" accept="image/*">
                                                        <small class="text-muted">Leave empty to keep current photo</small>
                                                        @error('photo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                        <div class="mt-2">
                                                            <img src="{{ $testimonial->photo }}" alt="Current photo" class="img-thumbnail" style="max-width: 100px;">
                                                        </div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Position</label>
                                                        <input type="number" name="position" class="form-control @error('position') is-invalid @enderror" value="{{ old('position', $testimonial->position) }}" min="0">
                                                        <small class="text-muted">Lower numbers appear first</small>
                                                        @error('position')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">Status <span class="text-danger">*</span></label>
                                                        <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                                            <option value="active" {{ old('status', $testimonial->status) === 'active' ? 'selected' : '' }}>Active</option>
                                                            <option value="inactive" {{ old('status', $testimonial->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                                        </select>
                                                        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary">Update Testimonial</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- Delete Modal -->
                                <div class="modal fade" id="deleteTestimonialModal{{ $testimonial->id }}" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form action="{{ route('admin.testimonials.destroy', $testimonial) }}" method="POST">
                                                @csrf
                                                @method('DELETE')
                                                <div class="modal-header bg-danger text-white">
                                                    <h5 class="modal-title">Delete Testimonial</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Are you sure you want to delete this testimonial?</p>
                                                    <div class="alert alert-warning">
                                                        <strong>{{ $testimonial->name }}</strong> - {{ $testimonial->occupation }}
                                                    </div>
                                                    <p class="text-danger"><i class="fa fa-exclamation-triangle me-2"></i>This action cannot be undone.</p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Delete</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No testimonials found. Click "Add Testimonial" to create one.</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createTestimonialModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('admin.testimonials.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add New Testimonial</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Occupation <span class="text-danger">*</span></label>
                        <input type="text" name="occupation" class="form-control @error('occupation') is-invalid @enderror" value="{{ old('occupation') }}" required>
                        @error('occupation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea name="message" rows="4" class="form-control @error('message') is-invalid @enderror" required>{{ old('message') }}</textarea>
                        @error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Photo <span class="text-danger">*</span></label>
                        <input type="file" name="photo" class="form-control @error('photo') is-invalid @enderror" accept="image/*" required>
                        <small class="text-muted">Accepted formats: JPG, PNG, GIF. Max size: 2MB</small>
                        @error('photo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Position</label>
                        <input type="number" name="position" class="form-control @error('position') is-invalid @enderror" value="{{ old('position', 0) }}" min="0">
                        <small class="text-muted">Lower numbers appear first</small>
                        @error('position')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                            <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Testimonial</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
