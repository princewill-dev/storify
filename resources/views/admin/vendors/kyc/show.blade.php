@extends('admin.layout')
@section('subtitle', 'Review vendor verification details')

@section('content')
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Application Details</h3>
                    <span class="text-xs text-slate-400">Submitted {{ optional($application->submitted_at)->diffForHumans() ?? 'n/a' }}</span>
                </div>
                @php($badge = $application->status_metadata)
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge['class'] }}">
                    {{ $badge['label'] }}
                </span>
            </div>
            <div class="px-5 py-4 space-y-3">
                <div class="flex">
                    <span class="w-40 text-xs text-slate-500 shrink-0">Legal name</span>
                    <span class="text-sm text-slate-900">{{ $application->legal_name }}</span>
                </div>
                <div class="flex">
                    <span class="w-40 text-xs text-slate-500 shrink-0">Phone number</span>
                    <span class="text-sm text-slate-700">{{ $application->phone_number }}</span>
                </div>
                <div class="flex">
                    <span class="w-40 text-xs text-slate-500 shrink-0">Date of birth</span>
                    <span class="text-sm text-slate-700">{{ optional($application->date_of_birth)->format('d M Y') ?? '—' }}</span>
                </div>
                <div class="flex">
                    <span class="w-40 text-xs text-slate-500 shrink-0">Address</span>
                    <span class="text-sm text-slate-700">
                        <div>{{ $application->address_line }}</div>
                        <div class="text-slate-500">{{ $application->city }}, {{ $application->state }}, {{ $application->country }}</div>
                    </span>
                </div>
                <div class="flex">
                    <span class="w-40 text-xs text-slate-500 shrink-0">Device</span>
                    <span class="text-sm text-slate-700">
                        <div>{{ ucfirst($application->device_type ?? 'Unknown') }}</div>
                        <div class="text-xs text-slate-400">{{ $application->browser }}</div>
                    </span>
                </div>
                <div class="flex">
                    <span class="w-40 text-xs text-slate-500 shrink-0">IP Address</span>
                    <span class="text-sm text-slate-700">{{ $application->ip_address ?? '—' }}</span>
                </div>
                <div class="flex">
                    <span class="w-40 text-xs text-slate-500 shrink-0">Identification</span>
                    <span class="text-sm">
                        @if($application->identification_document_path)
                            <a class="text-indigo-600 hover:underline" href="{{ asset('storage/'.$application->identification_document_path) }}" target="_blank" rel="noopener">
                                View uploaded document
                            </a>
                        @else
                            <span class="text-slate-400">Not provided</span>
                        @endif
                    </span>
                </div>
                @if($application->review_notes)
                <div class="flex border-t border-slate-100 pt-4 mt-1">
                    <span class="w-40 text-xs text-slate-500 shrink-0">Reviewer Notes</span>
                    <span class="text-sm text-slate-700">{{ $application->review_notes }}</span>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Vendor Snapshot</h3>
            </div>
            <div class="px-5 py-4 space-y-2">
                <div class="font-semibold text-slate-900">{{ $application->user->name }}</div>
                <div class="text-xs text-slate-500">{{ $application->user->email }}</div>
                <div class="text-xs text-slate-500">Phone: {{ $application->user->phone ?? '—' }}</div>
                <a href="{{ route('admin.vendors.show', $application->user) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 w-full justify-center mt-2">
                    View vendor profile
                </a>
            </div>
        </div>

        @if($isActionable)
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="text-sm font-semibold text-slate-900">Take Action</h3>
                </div>
                <div class="px-5 py-4 space-y-4">
                    <form method="POST" action="{{ route('admin.vendor-kyc.approve', $application) }}" class="space-y-3">
                        @csrf
                        <div>
                            <label for="approve_notes" class="block text-sm font-medium text-slate-700 mb-1">Notes (optional)</label>
                            <textarea id="approve_notes" name="review_notes" class="w-full px-3 py-2 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3" placeholder="Add context for audit log..."></textarea>
                        </div>
                        <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">
                            <i class="fi fi-rr-check-circle text-sm"></i> Approve Application
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.vendor-kyc.reject', $application) }}" class="space-y-3">
                        @csrf
                        <div>
                            <label for="reject_notes" class="block text-sm font-medium text-slate-700 mb-1">Rejection reason <span class="text-red-500">*</span></label>
                            <textarea id="reject_notes" name="review_notes" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 @error('review_notes') border-red-300 bg-red-50 @else border-slate-200 @enderror" rows="3" required placeholder="Explain why the application is rejected..."></textarea>
                            @error('review_notes')
                                <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-red-600 text-white hover:bg-red-700">
                            <i class="fi fi-rr-cross-circle text-sm"></i> Reject Application
                        </button>
                    </form>
                </div>
            </div>
        @else
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="px-5 py-6 text-center">
                    <i class="fi fi-rr-badge-check text-2xl text-slate-300 mb-2 block"></i>
                    <p class="text-sm text-slate-400">This application has already been {{ $statusLabelLower ?: strtolower($application->status) }}.</p>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
