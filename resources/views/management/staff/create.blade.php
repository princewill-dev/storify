@extends('management.layout')
@section('subtitle', 'Invite Staff')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Invite Staff" subtitle="Add a new team member to your business" />

<div x-data="staffCreate()">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
        <form action="{{ route('management.staff.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5" x-ref="form">
            @csrf

            <h3 class="text-sm font-semibold text-slate-800 mb-5">Staff Information</h3>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- Left: Form Fields --}}
                <div class="lg:col-span-2 space-y-5">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-management.form-input name="name" label="Full Name" placeholder="John Smith" required :error="$errors->first('name')" />
                        <x-management.form-input name="email" label="Email" type="email" placeholder="john@example.com" required :error="$errors->first('email')" />
                        <x-management.form-input name="phone" label="Phone" placeholder="08000000000" />
                    </div>

                    <hr class="border-slate-100">

                    <div>
                        <div class="flex items-center gap-2 mb-3">
                            <h3 class="text-sm font-semibold text-slate-800">Account Password</h3>
                            <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Optional — staff sets their own via invitation</span>
                        </div>
                        <button type="button" @click="showPassword = !showPassword" class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-700 transition-colors">
                            <i class="fi text-xs" :class="showPassword ? 'fi-rr-minus-circle' : 'fi-rr-plus-circle'"></i>
                            <span x-show="!showPassword">Set a password now</span>
                            <span x-show="showPassword">Remove password</span>
                        </button>
                        <div x-show="showPassword" x-collapse class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                            <x-management.form-input name="password" label="Password" type="password" placeholder="Min. 8 characters" />
                            <x-management.form-input name="password_confirmation" label="Confirm Password" type="password" placeholder="Re-enter password" />
                        </div>
                    </div>

                    <div x-data="{ showPin: false }">
                        <div class="flex items-center gap-2 mb-3">
                            <h3 class="text-sm font-semibold text-slate-800">POS PIN</h3>
                            <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">6-digit numeric PIN for sales authorization</span>
                        </div>
                        <button type="button" @click="showPin = !showPin" class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-700 transition-colors">
                            <i class="fi text-xs" :class="showPin ? 'fi-rr-minus-circle' : 'fi-rr-plus-circle'"></i>
                            <span x-show="!showPin">Set PIN now</span>
                            <span x-show="showPin">Remove PIN</span>
                        </button>
                        <div x-show="showPin" class="mt-3">
                            <input type="text" name="pin" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="off"
                                class="block w-48 rounded-lg border-slate-300 px-4 py-2.5 text-center text-lg font-bold tracking-[0.5em] shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                                placeholder="000000">
                            <p class="text-xs text-slate-400 mt-1">Staff will use this PIN to authorize POS sales</p>
                        </div>
                    </div>

                    <hr class="border-slate-100">

                    <div>
                        <x-management.form-input name="role" label="Role" type="select" :error="$errors->first('role')">
                            @foreach($roles as $role)
                            <option value="{{ $role->name }}">{{ $role->name }}</option>
                            @endforeach
                        </x-management.form-input>
                    </div>

                    <hr class="border-slate-100">

                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-slate-800">Documents</h3>
                            <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Optional — KYC, CV, certificates, etc.</span>
                        </div>

                        <div class="rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-4 transition-colors"
                             :class="{ 'border-blue-400 bg-blue-50/30': dragging }"
                             @dragover.prevent="dragging = true"
                             @dragleave.prevent="dragging = false"
                             @drop.prevent="handleDrop($event); dragging = false">

                            <template x-if="documents.length === 0">
                                <div class="text-center py-6">
                                    <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                                    <p class="mt-2 text-sm text-slate-500">Drag & drop files or <button type="button" class="font-medium text-blue-600 hover:text-blue-700" @click="$refs.docInput.click()">browse</button></p>
                                    <p class="mt-1 text-xs text-slate-400">PDF, DOCX, XLSX, JPG, PNG — max 5MB each</p>
                                </div>
                            </template>

                            <template x-if="documents.length > 0">
                                <div class="space-y-2">
                                    <template x-for="(doc, index) in documents" :key="doc.id">
                                        <div class="flex items-start gap-3 bg-white rounded-lg border border-slate-200 p-3">
                                            <template x-if="doc.preview">
                                                <img :src="doc.preview" class="h-12 w-12 rounded-lg object-cover shrink-0 border border-slate-100">
                                            </template>
                                            <template x-if="!doc.preview">
                                                <div class="h-12 w-12 rounded-lg flex items-center justify-center shrink-0 border"
                                                     :class="{
                                                         'bg-red-50 border-red-100': doc.ext === 'PDF',
                                                         'bg-blue-50 border-blue-100': doc.ext === 'DOCX' || doc.ext === 'DOC',
                                                         'bg-green-50 border-green-100': doc.ext === 'XLSX' || doc.ext === 'XLS',
                                                         'bg-slate-100 border-slate-200': !['PDF','DOCX','DOC','XLSX','XLS'].includes(doc.ext),
                                                     }">
                                                    <span class="text-[10px] font-bold uppercase"
                                                          :class="{
                                                              'text-red-500': doc.ext === 'PDF',
                                                              'text-blue-500': doc.ext === 'DOCX' || doc.ext === 'DOC',
                                                              'text-green-500': doc.ext === 'XLSX' || doc.ext === 'XLS',
                                                              'text-slate-500': !['PDF','DOCX','DOC','XLSX','XLS'].includes(doc.ext),
                                                          }"
                                                          x-text="doc.ext"></span>
                                                </div>
                                            </template>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-slate-700 truncate" x-text="doc.name"></p>
                                                <p class="text-xs text-slate-400" x-text="doc.size"></p>
                                                <input type="text"
                                                       class="mt-2 w-full rounded-md border-slate-200 text-xs focus:border-slate-400 focus:ring-slate-400"
                                                       :name="'document_tags[' + index + ']'"
                                                       :value="doc.tag"
                                                       @input="doc.tag = $event.target.value"
                                                       placeholder="Tag (e.g. KYC, CV, Certificate)">
                                            </div>
                                            <button type="button" @click="removeFile(index)" class="p-1 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg shrink-0 mt-1">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <div class="flex items-center justify-center mt-3" x-show="documents.length > 0">
                                <button type="button" @click="$refs.docInput.click()"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700 bg-white border border-blue-200 px-3 py-1.5 rounded-lg hover:bg-blue-50 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    Add more files
                                </button>
                            </div>

                            <input type="file" x-ref="docInput"
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,image/jpeg,image/png"
                                   class="hidden" multiple
                                   @change="handleDocs($event)">
                        </div>
                        @error('documents.*')<p class="text-xs text-red-500 mt-2">{{ $message }}</p>@enderror
                        @error('document_tags.*')<p class="text-xs text-red-500 mt-2">{{ $message }}</p>@enderror

                        <div x-ref="docInputs"></div>
                        <template x-for="(doc, index) in documents" :key="doc.id">
                            <input type="hidden" :name="'document_tags[' + index + ']'" :value="doc.tag">
                        </template>
                    </div>
                </div>

                {{-- Right: Profile Photo --}}
                <div class="lg:col-span-1">
                    <div class="sticky top-24 flex flex-col items-center gap-5 border-l border-slate-100 lg:pl-6">
                        <div class="relative">
                            <div class="w-28 h-28 sm:w-32 sm:h-32 rounded-full ring-4 ring-slate-100 overflow-hidden bg-slate-200 shadow-inner">
                                <img src="https://www.gravatar.com/avatar/00000000000000000000000000000000?d=mp&s=200" alt="" class="w-full h-full object-cover" x-ref="previewImg">
                            </div>
                            <label class="absolute -bottom-1 -right-1 w-9 h-9 bg-slate-900 rounded-full flex items-center justify-center cursor-pointer hover:bg-slate-700 transition-colors shadow-lg">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="handlePhoto($event)">
                            </label>
                        </div>
                        <p class="text-xs text-slate-400">JPEG, PNG or WebP — max 2MB</p>
                        @error('photo')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="hidden" x-ref="photoInput">

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">
                    <i class="fi fi-rr-paper-plane text-xs"></i> Send Invitation
                </button>
                <a href="{{ route('management.staff.index') }}" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('staffCreate', () => ({
        documents: [],
        dragging: false,
        docId: 0,
        showPassword: false,

        handlePhoto(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) { alert('Photo must be under 2MB.'); return; }
            const reader = new FileReader();
            reader.onload = (e) => { this.$refs.previewImg.src = e.target.result; };
            reader.readAsDataURL(file);
            const dt = new DataTransfer();
            dt.items.add(file);
            this.$refs.photoInput.files = dt.files;
        },

        handleDocs(event) {
            this.processFiles(event.target.files);
            event.target.value = '';
        },

        handleDrop(event) {
            this.processFiles(event.dataTransfer.files);
        },

        processFiles(fileList) {
            const allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
            const maxSize = 5 * 1024 * 1024;
            const files = Array.from(fileList);

            if (this.documents.length + files.length > 10) {
                alert('Maximum 10 documents allowed.');
                return;
            }

            for (const file of files) {
                const ext = file.name.split('.').pop().toLowerCase();
                if (!allowed.includes(ext)) {
                    alert('"' + file.name + '" is not a supported file type.');
                    continue;
                }
                if (file.size > maxSize) {
                    alert('"' + file.name + '" exceeds 5MB limit.');
                    continue;
                }

                const doc = {
                    id: ++this.docId,
                    file: file,
                    name: file.name,
                    ext: ext.toUpperCase(),
                    size: this.formatSize(file.size),
                    tag: '',
                    preview: null,
                };

                if (['jpg','jpeg','png'].includes(ext)) {
                    const reader = new FileReader();
                    reader.onload = (e) => { doc.preview = e.target.result; };
                    reader.readAsDataURL(file);
                }

                this.documents.push(doc);
                this.appendFileInput(file);
            }
        },

        appendFileInput(file) {
            const dt = new DataTransfer();
            dt.items.add(file);
            const input = document.createElement('input');
            input.type = 'file';
            input.name = 'documents[]';
            input.style.display = 'none';
            input.files = dt.files;
            this.$refs.docInputs.appendChild(input);
        },

        removeFile(index) {
            this.documents.splice(index, 1);
            this.rebuildFileInputs();
        },

        rebuildFileInputs() {
            const container = this.$refs.docInputs;
            container.innerHTML = '';
            this.documents.forEach((doc) => {
                const dt = new DataTransfer();
                dt.items.add(doc.file);
                const input = document.createElement('input');
                input.type = 'file';
                input.name = 'documents[]';
                input.style.display = 'none';
                input.files = dt.files;
                container.appendChild(input);
            });
        },

        formatSize(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            return (bytes / 1024).toFixed(1) + ' KB';
        },
    }));
});
</script>
@endpush
@endsection
