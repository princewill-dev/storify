@extends('admin.layout')
@section('title', 'Live First Application #' . $application->id)
@section('subtitle', 'Review customer credit application details')

@section('content')
    <div class="row gy-4">
        <div class="col-md-8">
            <!-- Application Details Card -->
            <div class="card mb-4">
                <div class="card-header border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0">Application Details</h5>
                        <span class="text-muted small">Submitted {{ optional($application->submitted_at)->diffForHumans() ?? 'n/a' }}</span>
                    </div>
                    @if($application->status === 'pending')
                        <span class="badge bg-warning">Pending Review</span>
                    @elseif($application->status === 'approved')
                        <span class="badge bg-success">Approved</span>
                    @elseif($application->status === 'rejected')
                        <span class="badge bg-danger">Rejected</span>
                    @endif
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Full Name</dt>
                        <dd class="col-sm-8">{{ $application->full_name }}</dd>

                        <dt class="col-sm-4">Date of Birth</dt>
                        <dd class="col-sm-8">{{ $application->date_of_birth->format('d M Y') }} ({{ $application->date_of_birth->age }} years old)</dd>

                        <dt class="col-sm-4">Phone Number</dt>
                        <dd class="col-sm-8">{{ $application->phone_number }}</dd>

                        <dt class="col-sm-4">Employer</dt>
                        <dd class="col-sm-8">{{ $application->employer_name }}</dd>

                        <dt class="col-sm-4">Years with Employer</dt>
                        <dd class="col-sm-8">{{ $application->years_with_employer }} years</dd>

                        <dt class="col-sm-4">State of Origin</dt>
                        <dd class="col-sm-8">{{ $application->state_of_origin }}, {{ $application->lga_of_origin }}</dd>

                        @if($application->community || $application->village)
                        <dt class="col-sm-4">Community/Village</dt>
                        <dd class="col-sm-8">{{ $application->community ?? 'N/A' }} / {{ $application->village ?? 'N/A' }}</dd>
                        @endif

                        <dt class="col-sm-4">Residential Address</dt>
                        <dd class="col-sm-8">
                            <div>{{ $application->residential_address }}</div>
                            <div>{{ $application->residential_lga }}, {{ $application->residential_state }}</div>
                        </dd>

                        @if($application->reviewed_at)
                        <hr>
                        <dt class="col-sm-4">Reviewed At</dt>
                        <dd class="col-sm-8">{{ $application->reviewed_at->format('d M Y H:i') }}</dd>

                        <dt class="col-sm-4">Reviewed By</dt>
                        <dd class="col-sm-8">{{ $application->reviewer->name ?? 'N/A' }}</dd>
                        @endif

                        @if($application->rejection_reason)
                        <hr>
                        <dt class="col-sm-4">Rejection Reason</dt>
                        <dd class="col-sm-8">
                            <div class="alert alert-danger mb-0">{{ $application->rejection_reason }}</div>
                        </dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Customer Info Card -->
            <div class="card">
                <div class="card-header border-0">
                    <h5 class="card-title mb-0">Customer Info</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-1 mb-3">
                        <span class="fw-semibold">{{ $application->user->name }}</span>
                        <span class="text-muted small">{{ $application->user->email }}</span>
                        <span class="text-muted small">Phone: {{ $application->user->phone ?? 'N/A' }}</span>
                        <div class="mt-2">
                            <span class="badge {{ $application->user->live_first_status->badgeClass() }}">
                                {{ $application->user->live_first_status->label() }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <strong class="small d-block mb-1">Store:</strong>
                        <span class="badge bg-info">{{ $application->store->name }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <!-- Documents Card -->
            <div class="card">
                <div class="card-header border-0">
                    <h5 class="card-title mb-0">Uploaded Documents ({{ $application->documents->count() }})</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Document Type</th>
                                    <th>File Name</th>
                                    <th>Size</th>
                                    <th>Verified</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($application->documents as $document)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                @if($document->document_type->value === 'video')
                                                    <i class="fi fi-rr-video-camera text-primary me-2"></i>
                                                @elseif(in_array($document->document_type->value, ['selfie']))
                                                    <i class="fi fi-rr-portrait text-info me-2"></i>
                                                @else
                                                    <i class="fi fi-rr-document text-secondary me-2"></i>
                                                @endif
                                                <span class="fw-semibold">{{ $document->document_type->label() }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="text-muted small">{{ $document->file_name }}</span>
                                        </td>
                                        <td>{{ $document->file_size_human }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('admin.live-first.document.toggle', [$application, $document]) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm {{ $document->verified ? 'btn-success' : 'btn-outline-secondary' }}">
                                                    @if($document->verified)
                                                        <i class="fi fi-rr-check me-1"></i> Verified
                                                    @else
                                                        <i class="fi fi-rr-circle me-1"></i> Click to Verify
                                                    @endif
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ $document->file_url }}" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fi fi-rr-eye me-1"></i> View
                                            </a>
                                            <a href="{{ $document->file_url }}" download class="btn btn-sm btn-outline-secondary">
                                                <i class="fi fi-rr-download me-1"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            No documents uploaded
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <!-- Action Card -->
            @if($isActionable)
                <div class="card">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">Take Action</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.live-first.approve', $application) }}" class="mb-3">
                            @csrf
                            <div class="alert alert-info small mb-3">
                                <i class="fi fi-rr-info me-1"></i>
                                Approving will set user status to <strong>VERIFIED</strong> and allow them to begin the 6-month testing period.
                            </div>
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Are you sure you want to approve this application?')">
                                <i class="fi fi-rr-check me-2"></i> Approve Application
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.live-first.reject', $application) }}">
                            @csrf
                            <div class="mb-3">
                                <label for="rejection_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                                <textarea id="rejection_reason" name="rejection_reason" class="form-control @error('rejection_reason') is-invalid @enderror" rows="4" required placeholder="Explain why the application is being rejected..."></textarea>
                                @error('rejection_reason')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Are you sure you want to reject this application?')">
                                <i class="fi fi-rr-cross me-2"></i> Reject Application
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body text-center text-muted py-4">
                        @if($application->status === 'approved')
                            <i class="fi fi-rr-badge-check fs-2 mb-2 text-success"></i>
                            <p class="mb-0">This application has been <strong>approved</strong>.</p>
                        @elseif($application->status === 'rejected')
                            <i class="fi fi-rr-cross-circle fs-2 mb-2 text-danger"></i>
                            <p class="mb-0">This application has been <strong>rejected</strong>.</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-6">
            <!-- Quick Stats Card -->
            <div class="card">
                <div class="card-header border-0">
                    <h6 class="card-title mb-0">Document Verification</h6>
                </div>
                <div class="card-body">
                    @php
                        $totalDocs = $application->documents->count();
                        $verifiedDocs = $application->documents->where('verified', true)->count();
                        $percentage = $totalDocs > 0 ? round(($verifiedDocs / $totalDocs) * 100) : 0;
                    @endphp
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">{{ $verifiedDocs }} of {{ $totalDocs }} verified</span>
                        <span class="fw-semibold">{{ $percentage }}%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: {{ $percentage }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
