@extends('management.layout')
@section('subtitle', 'Profile')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Profile" subtitle="Manage your account and photo" />

<div x-data="photoUpload('{{ $user->photoUrl() }}', {{ json_encode((bool) $user->photo_path) }})">
    @if(session('success'))
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif
    @if(session('password_success'))
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('password_success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {{-- Left: Profile Details --}}
        <div class="lg:col-span-3 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-slate-800 mb-5">Personal Information</h3>
                <form action="{{ route('management.profile.update') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf @method('PUT')
                    <x-management.form-input name="name" label="Full Name" :value="old('name', $user->name)" required :error="$errors->first('name')" />
                    <x-management.form-input name="phone" label="Phone Number" :value="old('phone', $user->phone)" />
                    <x-management.form-input name="email" label="Email Address" type="email" :value="$user->email" disabled />
                    {{-- hidden inputs for photo upload --}}
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="hidden" x-ref="photoInput" @change="handleFile($event)">
                    <input type="hidden" name="remove_photo" x-model="removePhotoFlag">
                    @error('photo')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    <div class="pt-2">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-slate-800 mb-5">Change Password</h3>
                <form action="{{ route('management.profile.password') }}" method="POST" class="space-y-4">
                    @csrf @method('PUT')
                    <x-management.form-input name="current_password" label="Current Password" type="password" required :error="$errors->first('current_password')" />
                    <x-management.form-input name="password" label="New Password" type="password" required :error="$errors->first('password')" />
                    <x-management.form-input name="password_confirmation" label="Confirm New Password" type="password" required />
                    <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Update Password</button>
                </form>
            </div>
        </div>

        {{-- Right: Photo Card --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6 sticky top-24">
                <h3 class="text-sm font-semibold text-slate-800 mb-5">Profile Photo</h3>
                <div class="flex flex-col items-center gap-5">
                    <div class="relative">
                        <div class="w-28 h-28 sm:w-32 sm:h-32 rounded-full ring-4 ring-slate-100 overflow-hidden bg-slate-200 shadow-inner">
                            <img :src="preview" alt="" class="w-full h-full object-cover" x-show="!loading">
                            <div x-show="loading" class="w-full h-full flex items-center justify-center bg-slate-100">
                                <svg class="animate-spin h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            </div>
                        </div>
                        <label class="absolute -bottom-1 -right-1 w-9 h-9 bg-slate-900 rounded-full flex items-center justify-center cursor-pointer hover:bg-slate-700 transition-colors shadow-lg">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="handleFile($event)">
                        </label>
                    </div>
                    <div class="text-center">
                        <p class="text-sm font-semibold text-slate-800">{{ $user->name }}</p>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $user->email }}</p>
                    </div>
                    <div class="flex items-center gap-2" x-show="hasPhoto">
                        <button type="button" @click="removePhoto()" class="text-xs text-red-500 hover:text-red-700 font-medium">Remove photo</button>
                    </div>
                    <div class="w-full pt-4 border-t border-slate-100">
                        <p class="text-[11px] text-slate-400 text-center leading-relaxed">
                            JPEG, PNG or WebP.<br>Maximum 2MB.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('photoUpload', (initialPhoto, hasExistingPhoto) => ({
        preview: initialPhoto,
        hasPhoto: hasExistingPhoto,
        removePhotoFlag: '0',
        loading: false,

        handleFile(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                alert('Photo must be under 2MB.');
                return;
            }
            this.loading = true;
            const reader = new FileReader();
            reader.onload = (e) => {
                this.preview = e.target.result;
                this.hasPhoto = true;
                this.removePhotoFlag = '0';
                this.loading = false;
            };
            reader.readAsDataURL(file);
            // sync file to the hidden input in the details form
            const dt = new DataTransfer();
            dt.items.add(file);
            this.$refs.photoInput.files = dt.files;
        },

        removePhoto() {
            this.preview = 'https://www.gravatar.com/avatar/{{ md5($user->email ?: $user->name) }}?d=mp&s=200';
            this.hasPhoto = false;
            this.removePhotoFlag = '1';
            if (this.$refs.photoInput) this.$refs.photoInput.value = '';
            const cardInput = this.$el.querySelector('input[type="file"]');
            if (cardInput) cardInput.value = '';
        }
    }));
});
</script>
@endpush
@endsection
