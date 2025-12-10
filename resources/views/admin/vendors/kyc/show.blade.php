@extends('admin.layout')
@section('subtitle', 'Review vendor verification details')

@section('content')
    <div class="row gy-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0">Application Details</h5>
                        <span class="text-muted small">Submitted {{ optional($application->submitted_at)->diffForHumans() ?? 'n/a' }}</span>
                    </div>
                    @php($badge = $application->status_metadata)
                    <span class="badge {{ $badge['class'] }}">
                        {{ $badge['label'] }}
                    </span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Legal name</dt>
                        <dd class="col-sm-8">{{ $application->legal_name }}</dd>

                        <dt class="col-sm-4">Phone number</dt>
                        <dd class="col-sm-8">{{ $application->phone_number }}</dd>

                        <dt class="col-sm-4">Date of birth</dt>
                        <dd class="col-sm-8">{{ optional($application->date_of_birth)->format('d M Y') ?? '—' }}</dd>

                        <dt class="col-sm-4">Address</dt>
                        <dd class="col-sm-8">
                            <div>{{ $application->address_line }}</div>
                            <div>{{ $application->city }}, {{ $application->state }}, {{ $application->country }}</div>
                        </dd>

                        <dt class="col-sm-4">Device</dt>
                        <dd class="col-sm-8">
                            <div>{{ ucfirst($application->device_type ?? 'Unknown') }}</div>
                            <div class="text-muted small">{{ $application->browser }}</div>
                        </dd>

                        <dt class="col-sm-4">IP Address</dt>
                        <dd class="col-sm-8">{{ $application->ip_address ?? '—' }}</dd>

                        <dt class="col-sm-4">Identification</dt>
                        <dd class="col-sm-8">
                            @if($application->identification_document_path)
                                <a class="link-primary" href="{{ asset('storage/'.$application->identification_document_path) }}" target="_blank" rel="noopener">
                                    View uploaded document
                                </a>
                            @else
                                <span class="text-muted">Not provided</span>
                            @endif
                        </dd>
                        <hr>
                        @if($application->review_notes)
                            <dt class="col-sm-4">Reviewer Notes</dt>
                            <dd class="col-sm-8">{{ $application->review_notes }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

        </div>

        <div class="col-xl-4 d-flex flex-column gap-4">
            <div class="card">
                <div class="card-header border-0">
                    <h5 class="card-title mb-0">Vendor Snapshot</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-1 mb-3">
                        <span class="fw-semibold">{{ $application->vendor->name }}</span>
                        <span class="text-muted small">{{ $application->vendor->email }}</span>
                        <span class="text-muted small">Phone: {{ $application->vendor->phone ?? '—' }}</span>
                    </div>
                    <a class="btn btn-outline-primary btn-sm w-100" href="{{ route('admin.vendors.show', $application->vendor) }}">
                        View vendor profile
                    </a>
                </div>
            </div>

            @if($isActionable)
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">Take Action</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.vendor-kyc.approve', $application) }}" class="mb-3">
                            @csrf
                            <div class="mb-3">
                                <label for="approve_notes" class="form-label">Notes (optional)</label>
                                <textarea id="approve_notes" name="review_notes" class="form-control" rows="3" placeholder="Add context for audit log..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                Approve Application
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.vendor-kyc.reject', $application) }}">
                            @csrf
                            <div class="mb-3">
                                <label for="reject_notes" class="form-label">Rejection reason <span class="text-danger">*</span></label>
                                <textarea id="reject_notes" name="review_notes" class="form-control @error('review_notes') is-invalid @enderror" rows="3" required placeholder="Explain why the application is rejected..."></textarea>
                                @error('review_notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-danger w-100">
                                Reject Application
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="card h-100">
                    <div class="card-body text-center text-muted">
                        <i class="fi fi-rr-badge-check fs-2 mb-2"></i>
                        <p class="mb-0">This application has already been {{ $statusLabelLower ?: strtolower($application->status) }}.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
