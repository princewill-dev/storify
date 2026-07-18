@extends('admin.layout')
@section('subtitle', 'Testimonials Management')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h2 class="text-lg font-bold text-slate-900">Testimonials Management</h2>
    <button type="button" onclick="openModal('createTestimonialModal')" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">
        <i class="fi fi-rr-plus text-sm"></i> Add Testimonial
    </button>
</div>

@if(session('success'))
    <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-sm text-emerald-700 flex items-center justify-between">
        <span><i class="fi fi-rr-check-circle text-emerald-500 mr-2"></i>{{ session('success') }}</span>
        <button type="button" class="text-emerald-400 hover:text-emerald-600" onclick="this.parentElement.remove()">&times;</button>
    </div>
@endif

@if(session('error'))
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700 flex items-center justify-between">
        <span><i class="fi fi-rr-exclamation text-red-500 mr-2"></i>{{ session('error') }}</span>
        <button type="button" class="text-red-400 hover:text-red-600" onclick="this.parentElement.remove()">&times;</button>
    </div>
@endif

<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
            <tr>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-[60px]">Photo</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Name</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Occupation</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Message</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-[80px]">Position</th>
                <th class="py-3 px-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider w-[100px]">Status</th>
                <th class="py-3 px-4 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider w-[120px]">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($testimonials as $testimonial)
            <tr class="hover:bg-slate-50/50">
                <td class="py-3 px-4">
                    <img src="{{ $testimonial->photo }}" alt="{{ $testimonial->name }}" class="w-[50px] h-[50px] rounded-full object-cover border border-slate-200">
                </td>
                <td class="py-3 px-4 text-slate-700">{{ $testimonial->name }}</td>
                <td class="py-3 px-4 text-slate-700">{{ $testimonial->occupation }}</td>
                <td class="py-3 px-4 text-slate-600 text-sm">{{ Str::limit($testimonial->message, 100) }}</td>
                <td class="py-3 px-4 text-slate-700">{{ $testimonial->position }}</td>
                <td class="py-3 px-4">
                    @if($testimonial->status === 'active')
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                    @endif
                </td>
                <td class="py-3 px-4 text-right">
                    <div class="flex items-center justify-end gap-1">
                        <button type="button" onclick="openModal('editTestimonialModal{{ $testimonial->id }}')" class="inline-flex items-center justify-center p-1.5 text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-lg" title="Edit">
                            <i class="fi fi-rr-pencil text-sm"></i>
                        </button>
                        <button type="button" onclick="openModal('deleteTestimonialModal{{ $testimonial->id }}')" class="inline-flex items-center justify-center p-1.5 text-red-600 hover:bg-red-50 rounded-lg" title="Delete">
                            <i class="fi fi-rr-trash text-sm"></i>
                        </button>
                    </div>
                </td>
            </tr>

            <!-- Edit Modal -->
            <div id="editTestimonialModal{{ $testimonial->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
                <div class="flex items-center justify-center min-h-screen p-4">
                    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('editTestimonialModal{{ $testimonial->id }}')"></div>
                    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 max-h-[85vh] overflow-y-auto">
                        <div class="flex items-center justify-between mb-4">
                            <h5 class="text-base font-semibold text-slate-900">Edit Testimonial</h5>
                            <button onclick="closeModal('editTestimonialModal{{ $testimonial->id }}')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
                        </div>
                        <form action="{{ route('admin.testimonials.update', $testimonial) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                            @csrf
                            @method('PUT')
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Name <span class="text-red-500">*</span></label>
                                <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('name') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('name', $testimonial->name) }}" required>
                                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Occupation <span class="text-red-500">*</span></label>
                                <input type="text" name="occupation" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('occupation') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('occupation', $testimonial->occupation) }}" required>
                                @error('occupation')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Message <span class="text-red-500">*</span></label>
                                <textarea name="message" rows="4" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('message') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" required>{{ old('message', $testimonial->message) }}</textarea>
                                @error('message')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Photo</label>
                                <input type="file" name="photo" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 @error('photo') border-red-300 @enderror" accept="image/*">
                                <p class="mt-1 text-xs text-slate-400">Leave empty to keep current photo</p>
                                @error('photo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                <div class="mt-2">
                                    <img src="{{ $testimonial->photo }}" alt="Current photo" class="w-20 h-20 rounded border border-slate-200 object-cover">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Position</label>
                                <input type="number" name="position" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('position') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('position', $testimonial->position) }}" min="0">
                                <p class="mt-1 text-xs text-slate-400">Lower numbers appear first</p>
                                @error('position')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Status <span class="text-red-500">*</span></label>
                                <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('status') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" required>
                                    <option value="active" {{ old('status', $testimonial->status) === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="inactive" {{ old('status', $testimonial->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                </select>
                                @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
                                <button type="button" onclick="closeModal('editTestimonialModal{{ $testimonial->id }}')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                                <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Update Testimonial</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Delete Modal -->
            <div id="deleteTestimonialModal{{ $testimonial->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
                <div class="flex items-center justify-center min-h-screen p-4">
                    <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('deleteTestimonialModal{{ $testimonial->id }}')"></div>
                    <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h5 class="text-base font-semibold text-red-600">Delete Testimonial</h5>
                            <button onclick="closeModal('deleteTestimonialModal{{ $testimonial->id }}')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
                        </div>
                        <form action="{{ route('admin.testimonials.destroy', $testimonial) }}" method="POST" class="space-y-4">
                            @csrf
                            @method('DELETE')
                            <p class="text-sm text-slate-600">Are you sure you want to delete this testimonial?</p>
                            <div class="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-sm">
                                <strong class="text-slate-800">{{ $testimonial->name }}</strong> - {{ $testimonial->occupation }}
                            </div>
                            <p class="text-xs text-red-600"><i class="fi fi-rr-triangle-warning mr-1"></i>This action cannot be undone.</p>
                            <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
                                <button type="button" onclick="closeModal('deleteTestimonialModal{{ $testimonial->id }}')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                                <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @empty
            <tr>
                <td colspan="7" class="py-12 text-center">
                    <i class="fi fi-rr-inbox text-3xl text-slate-300 mb-2 block"></i>
                    <p class="text-slate-400">No testimonials found. Click "Add Testimonial" to create one.</p>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<!-- Create Modal -->
<div id="createTestimonialModal" class="hidden fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeModal('createTestimonialModal')"></div>
        <div class="relative bg-white rounded-xl shadow-xl border border-slate-200 w-full max-w-md p-6 max-h-[85vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-4">
                <h5 class="text-base font-semibold text-slate-900">Add New Testimonial</h5>
                <button onclick="closeModal('createTestimonialModal')" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
            </div>
            <form action="{{ route('admin.testimonials.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('name') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('name') }}" required>
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Occupation <span class="text-red-500">*</span></label>
                    <input type="text" name="occupation" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('occupation') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('occupation') }}" required>
                    @error('occupation')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Message <span class="text-red-500">*</span></label>
                    <textarea name="message" rows="4" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('message') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" required>{{ old('message') }}</textarea>
                    @error('message')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Photo <span class="text-red-500">*</span></label>
                    <input type="file" name="photo" class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 @error('photo') border-red-300 @enderror" accept="image/*" required>
                    <p class="mt-1 text-xs text-slate-400">Accepted formats: JPG, PNG, GIF. Max size: 2MB</p>
                    @error('photo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Position</label>
                    <input type="number" name="position" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('position') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" value="{{ old('position', 0) }}" min="0">
                    <p class="mt-1 text-xs text-slate-400">Lower numbers appear first</p>
                    @error('position')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status <span class="text-red-500">*</span></label>
                    <select name="status" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('status') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" required>
                        <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                    @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeModal('createTestimonialModal')" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Create Testimonial</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
