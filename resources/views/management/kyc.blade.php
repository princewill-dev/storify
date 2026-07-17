@extends('management.layout')
@section('subtitle', 'KYC Verification')

@section('content')
<x-management.page-header :breadcrumbs="$breadcrumbs" title="KYC Verification" subtitle="Verify your identity to activate your stores" />

<div class="max-w-2xl">
    {{-- Status Card --}}
    <x-management.card>
        @if($kycApp && in_array($kycApp->status, ['submitted', 'approved', 'rejected']))
            @if($kycApp->status === 'approved')
            <div class="flex items-center gap-3 mb-4">
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Verified</span>
                <span class="text-sm text-slate-400">Approved {{ $kycApp->approved_at?->diffForHumans() }}</span>
            </div>
            <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-xl text-sm text-emerald-800">
                <p class="font-semibold">Your identity has been verified</p>
                <p class="mt-1 text-emerald-600">You can now activate your stores and start selling. Your stores will be live to customers.</p>
            </div>
            @elseif($kycApp->status === 'submitted')
            <div class="flex items-center gap-3 mb-4">
                <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">Under Review</span>
                <span class="text-sm text-slate-400">Submitted {{ $kycApp->submitted_at?->diffForHumans() }}</span>
            </div>
            <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800">
                <p class="font-semibold">Your KYC is under review</p>
                <p class="mt-1 text-amber-600">We'll notify you once your documents have been verified. This usually takes 1-2 business days.</p>
            </div>
            @elseif($kycApp->status === 'rejected')
            <div class="flex items-center gap-3 mb-4">
                <span class="inline-flex items-center rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Rejected</span>
                <span class="text-sm text-slate-400">{{ $kycApp->rejected_at?->diffForHumans() }}</span>
            </div>
            <div class="p-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-800 mb-4">
                <p class="font-semibold">Your KYC application was not approved</p>
                @if($kycApp->review_notes)
                <p class="mt-1 text-red-600"><strong>Reason:</strong> {{ $kycApp->review_notes }}</p>
                @endif
            </div>
            <button onclick="openKycModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Resubmit KYC</button>
            @endif
        @else
            <div class="flex items-center gap-3 mb-4">
                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Not Verified</span>
            </div>
            <div class="p-4 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-600 mb-4">
                <p class="font-semibold">Identity verification required</p>
                <p class="mt-1">Submit your KYC documents to unlock store activation. This is required before your stores can go live.</p>
            </div>
            <button onclick="openKycModal()" class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Submit KYC Documents</button>
        @endif
    </x-management.card>
</div>
@endsection

@push('modals')
{{-- KYC Submission Modal --}}
<div id="kycModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/50" onclick="closeKycModal()"></div>
        <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-white px-6 py-4 border-b border-slate-100 flex items-center justify-between rounded-t-2xl z-10">
                <h3 class="text-base font-semibold text-slate-800">{{ $kycApp && $kycApp->status === 'rejected' ? 'Resubmit KYC' : 'Submit KYC Documents' }}</h3>
                <button onclick="closeKycModal()" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">&times;</button>
            </div>
            <form action="{{ route('management.kyc.submit') }}" method="POST" enctype="multipart/form-data" class="p-6 space-y-5">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Full Legal Name <span class="text-red-400">*</span></label>
                        <input type="text" name="legal_name" value="{{ old('legal_name', $user->name) }}" required placeholder="As on your ID" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Phone Number <span class="text-red-400">*</span></label>
                        <input type="tel" name="phone_number" value="{{ old('phone_number', $user->phone) }}" required placeholder="+234 800 000 0000" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Date of Birth <span class="text-red-400">*</span></label>
                    <input type="date" name="date_of_birth" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Address <span class="text-red-400">*</span></label>
                    <input type="text" name="address_line" required placeholder="Street address" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">City <span class="text-red-400">*</span></label>
                        <input type="text" name="city" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">State <span class="text-red-400">*</span></label>
                        <input type="text" name="state" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Country <span class="text-red-400">*</span></label>
                        <input type="text" name="country" required value="{{ old('country', 'Nigeria') }}" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Document Type <span class="text-red-400">*</span></label>
                        <select name="kyc_document_type_id" required class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">Select type</option>
                            @foreach($documentTypes as $doc)
                                <option value="{{ $doc->id }}">{{ $doc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Document ID Number <span class="text-red-400">*</span></label>
                        <input type="text" name="kyc_document_id" required placeholder="NIN, BVN, Passport number" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Identification Document</label>
                    <input type="file" name="identification_document" accept=".jpg,.jpeg,.png,.pdf"
                        class="block w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-5 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <p class="text-[11px] text-slate-400 mt-1">JPG, PNG, or PDF. Max 5MB.</p>
                    @error('identification_document') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Selfie Photo</label>
                    <input type="file" name="selfie_image" accept=".jpg,.jpeg,.png"
                        class="block w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-5 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <p class="text-[11px] text-slate-400 mt-1">Clear photo of your face. Max 4MB.</p>
                    @error('selfie_image') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="flex-1 py-2.5 bg-slate-900 text-white text-sm font-semibold rounded-lg hover:bg-slate-800 transition-colors">Submit for Review</button>
                    <button type="button" onclick="closeKycModal()" class="flex-1 py-2 border border-slate-200 text-sm rounded-lg hover:bg-slate-50">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
function openKycModal() { document.getElementById('kycModal').classList.remove('hidden'); }
function closeKycModal() { document.getElementById('kycModal').classList.add('hidden'); }
@if($errors->any())
document.addEventListener('DOMContentLoaded', () => openKycModal());
@endif
</script>
@endpush
