@extends('management.layout')
@section('subtitle', 'Edit Staff')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="Edit: {{ $staff->name }}" subtitle="Update team member details and documents" />

<div x-data="staffEdit()">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
        <form action="{{ route('management.staff.update', $staff) }}" method="POST" enctype="multipart/form-data" class="space-y-5" x-ref="form">
            @csrf @method('PUT')

            @if($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-disc pl-4 space-y-1">
                    @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <h3 class="text-sm font-semibold text-slate-800 mb-5">Staff Information</h3>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {{-- Left: Form Fields --}}
                <div class="lg:col-span-2 space-y-5">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-management.form-input name="name" label="Full Name" placeholder="John Smith" :value="old('name', $staff->name)" required :error="$errors->first('name')" />
                        <x-management.form-input name="email" label="Email" type="email" :value="$staff->email" disabled />
                        <x-management.form-input name="phone" label="Phone" placeholder="08000000000" :value="old('phone', $staff->phone)" />
                    </div>

                    <div x-data="{ showPin: false }">
                        <div class="flex items-center gap-2 mb-1">
                            <h3 class="text-sm font-semibold text-slate-800">POS PIN</h3>
                            @if($staff->pos_pin)
                            <span class="inline-flex items-center gap-0.5 text-[10px] font-medium text-emerald-600 bg-emerald-50 rounded-full px-2 py-0.5"><span class="w-1 h-1 rounded-full bg-emerald-500"></span> Set</span>
                            @endif
                        </div>
                        <button type="button" @click="showPin = !showPin" class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-700 transition-colors">
                            <i class="fi text-xs" :class="showPin ? 'fi-rr-minus-circle' : 'fi-rr-plus-circle'"></i>
                            <span x-show="!showPin">{{ $staff->pos_pin ? 'Change PIN' : 'Set PIN' }}</span>
                            <span x-show="showPin">Cancel</span>
                        </button>
                        <div x-show="showPin" class="mt-3">
                            <input type="text" name="pin" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="off"
                                class="block w-48 rounded-lg border-slate-300 px-4 py-2.5 text-center text-lg font-bold tracking-[0.5em] shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                                placeholder="000000">
                            <p class="text-xs text-slate-400 mt-1">Enter new 6-digit PIN or leave empty to clear</p>
                        </div>
                    </div>

                    <hr class="border-slate-100">

                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 mb-3">Roles</h3>
                        <div class="flex items-center gap-2 flex-wrap">
                            <template x-for="role in selectedRoles" :key="role">
                                <span class="inline-flex items-center gap-1 rounded-md bg-blue-50 border border-blue-200 px-2 py-0.5 text-xs font-medium text-blue-700" x-text="role"></span>
                            </template>
                            <span x-show="selectedRoles.length === 0" class="text-xs text-slate-400">No roles assigned</span>
                        </div>
                        <button type="button" @click="showRolesModal = true" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                            Manage Roles
                        </button>
                        @error('roles')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                        @error('roles.*')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <hr class="border-slate-100">

                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold text-slate-800">Documents</h3>
                            <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Optional — KYC, CV, certificates, etc.</span>
                        </div>

                        {{-- Existing uploaded documents --}}
                        @if($staff->documents->isNotEmpty())
                        <div class="mb-4 space-y-2">
                            <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Uploaded</p>
                            @foreach($staff->documents as $doc)
                            <div class="flex items-start gap-3 bg-slate-50 rounded-lg border border-slate-200 p-3" data-doc-id="{{ $doc->id }}">
                                @if($doc->isImage())
                                <img src="{{ $doc->url() }}" class="h-12 w-12 rounded-lg object-cover shrink-0 border border-slate-100">
                                @else
                                <div class="h-12 w-12 rounded-lg flex items-center justify-center shrink-0 border
                                    @if(in_array($doc->extension(), ['PDF'])) bg-red-50 border-red-100 text-red-500
                                    @elseif(in_array($doc->extension(), ['DOCX','DOC'])) bg-blue-50 border-blue-100 text-blue-500
                                    @elseif(in_array($doc->extension(), ['XLSX','XLS'])) bg-green-50 border-green-100 text-green-500
                                    @else bg-slate-100 border-slate-200 text-slate-500 @endif">
                                    <span class="text-[10px] font-bold uppercase">{{ $doc->extension() }}</span>
                                </div>
                                @endif
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-slate-700 truncate">{{ $doc->original_name }}</p>
                                    <p class="text-xs text-slate-400">{{ $doc->formattedSize() }} &middot; {{ $doc->tag ?: 'No tag' }}</p>
                                </div>
                                <button type="button" @click="markForDeletion({{ $doc->id }})" class="p-1 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg shrink-0 mt-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            @endforeach
                        </div>
                        @endif

                        {{-- New document upload area --}}
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

                        {{-- Hidden input for document IDs to delete --}}
                        <template x-for="id in deletingDocIds" :key="id">
                            <input type="hidden" name="delete_document_ids[]" :value="id">
                        </template>
                    </div>
                </div>

                {{-- Right: Profile Photo --}}
                <div class="lg:col-span-1">
                    <div class="sticky top-24 flex flex-col items-center gap-5 border-l border-slate-100 lg:pl-6">
                        <div class="relative">
                            <div class="w-28 h-28 sm:w-32 sm:h-32 rounded-full ring-4 ring-slate-100 overflow-hidden bg-slate-200 shadow-inner">
                                <img :src="preview" alt="" class="w-full h-full object-cover">
                            </div>
                            <label class="absolute -bottom-1 -right-1 w-9 h-9 bg-slate-900 rounded-full flex items-center justify-center cursor-pointer hover:bg-slate-700 transition-colors shadow-lg">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="hidden" @change="handlePhoto($event)">
                            </label>
                        </div>
                        <p class="text-xs text-slate-400">JPEG, PNG or WebP — max 2MB</p>
                        <button type="button" @click="removePhoto()" x-show="hasPhoto" class="text-xs text-red-500 hover:text-red-700 font-medium">Remove photo</button>
                        <input type="hidden" name="remove_photo" x-model="removePhotoFlag">
                        @error('photo')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <template x-for="role in selectedRoles" :key="role">
                <input type="hidden" name="roles[]" :value="role">
            </template>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Save Changes</button>
                <a href="{{ route('management.staff.index') }}" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Cancel</a>
            </div>
        </form>

        {{-- Roles Management Modal --}}
        <div x-show="showRolesModal" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" x-cloak>
            <div class="flex min-h-full items-center justify-center p-4">
                <div x-show="showRolesModal" x-transition class="fixed inset-0 bg-slate-900/50" @click="showRolesModal = false"></div>
                <div x-show="showRolesModal" x-transition class="relative w-full max-w-4xl bg-white rounded-2xl shadow-xl max-h-[85vh] flex flex-col">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">Manage Roles</h2>
                            <p class="text-xs text-slate-500 mt-0.5">{{ $staff->name }} &mdash; select one or more roles to assign</p>
                        </div>
                        <button @click="showRolesModal = false" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="overflow-y-auto p-6 flex-1">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach($roles as $role)
                            <label class="relative bg-white rounded-xl border-2 cursor-pointer transition-all"
                                   :class="selectedRoles.includes('{{ $role->name }}') ? 'border-blue-400 bg-blue-50/30' : 'border-slate-200 hover:border-slate-300'">
                                <input type="checkbox"
                                       value="{{ $role->name }}"
                                       class="absolute top-3 right-3 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                       :checked="selectedRoles.includes('{{ $role->name }}')"
                                       @change="toggleRole('{{ $role->name }}')">
                                <div class="p-4 pr-10">
                                    <h3 class="text-sm font-semibold text-slate-800">{{ $role->name }}</h3>
                                    @if($role->permissions->isNotEmpty())
                                    <div class="flex flex-wrap gap-1 mt-2">
                                        @foreach($role->permissions->take(4) as $perm)
                                        <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500">{{ $perm->name }}</span>
                                        @endforeach
                                        @if($role->permissions->count() > 4)
                                        <span class="inline-flex items-center rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-400">+{{ $role->permissions->count() - 4 }} more</span>
                                        @endif
                                    </div>
                                    @endif
                                </div>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center justify-between px-6 py-4 border-t border-slate-100 shrink-0">
                        <p class="text-xs text-slate-400"><span x-text="selectedRoles.length">0</span> role(s) selected</p>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="showRolesModal = false" class="px-4 py-2 text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">Cancel</button>
                            <button type="button" @click="closeRolesModal()" class="px-4 py-2 text-sm font-semibold text-white bg-slate-900 hover:bg-slate-800 rounded-lg transition-colors">Add Roles</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('staffEdit', () => ({
        preview: '{{ $staff->photoUrl() }}',
        hasPhoto: {{ json_encode((bool) $staff->photo_path) }},
        removePhotoFlag: '0',

        documents: [],
        deletingDocIds: [],
        dragging: false,
        docId: 0,

        selectedRoles: @json($assignedRoles),
        showRolesModal: false,

        toggleRole(name) {
            const idx = this.selectedRoles.indexOf(name);
            if (idx > -1) {
                this.selectedRoles.splice(idx, 1);
            } else {
                this.selectedRoles.push(name);
            }
        },

        closeRolesModal() {
            this.showRolesModal = false;
        },

        handlePhoto(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) { alert('Photo must be under 2MB.'); return; }
            const reader = new FileReader();
            reader.onload = (e) => {
                this.preview = e.target.result;
                this.hasPhoto = true;
                this.removePhotoFlag = '0';
            };
            reader.readAsDataURL(file);
        },

        removePhoto() {
            this.preview = 'https://www.gravatar.com/avatar/{{ md5($staff->email ?: $staff->name) }}?d=mp&s=200';
            this.hasPhoto = false;
            this.removePhotoFlag = '1';
        },

        markForDeletion(id) {
            if (this.deletingDocIds.includes(id)) return;
            this.deletingDocIds.push(id);
            const el = document.querySelector(`[data-doc-id="${id}"]`);
            if (el) el.classList.add('opacity-40', 'pointer-events-none');
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
