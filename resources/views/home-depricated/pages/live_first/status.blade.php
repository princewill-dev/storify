@extends('home.layout')
@section('title', 'Live First - Application Status')

@section('content')
<br>
<br>
<br>
<br>
<div class="status-page py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="status-card shadow">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-5">
                            <h2 class="fw-bold mb-3" style="color: #0D775E;">Application Status</h2>
                            <p class="text-muted">Track your Live First application progress</p>
                        </div>

                        <!-- Status Badge -->
                        <div class="text-center mb-5">
                            @if($application->status === 'pending')
                                <span class="badge bg-warning text-dark fs-5 px-4 py-3">
                                    <i class="fa fa-clock me-2"></i> Pending Review
                                </span>
                                <p class="text-muted mt-3">Your application is being reviewed by our team</p>
                            @elseif($application->status === 'approved')
                                <span class="badge bg-success fs-5 px-4 py-3">
                                    <i class="fa fa-check-circle me-2"></i> Approved
                                </span>
                                <p class="text-muted mt-3">Your KYC has been approved!</p>
                            @elseif($application->status === 'rejected')
                                <span class="badge bg-danger fs-5 px-4 py-3">
                                    <i class="fa fa-times-circle me-2"></i> Rejected
                                </span>
                                <p class="text-muted mt-3">{{ $application->rejection_reason ?? 'Your application was not approved' }}</p>
                            @endif
                        </div>

                        <!-- Application Details -->
                        <div class="section-header mb-4">
                            <h5 class="fw-semibold"><i class="fa fa-info-circle text-success me-2"></i> Application Details</h5>
                            <hr>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label class="text-muted small">Full Name</label>
                                    <p class="fw-semibold mb-0">{{ $application->full_name }}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label class="text-muted small">Date of Birth</label>
                                    <p class="fw-semibold mb-0">{{ $application->date_of_birth->format('M d, Y') }}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label class="text-muted small">Phone Number</label>
                                    <p class="fw-semibold mb-0">{{ $application->phone_number }}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label class="text-muted small">Employer</label>
                                    <p class="fw-semibold mb-0">{{ $application->employer_name }}</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label class="text-muted small">Years with Employer</label>
                                    <p class="fw-semibold mb-0">{{ $application->years_with_employer }} years</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-item">
                                    <label class="text-muted small">Submitted On</label>
                                    <p class="fw-semibold mb-0">{{ $application->submitted_at?->format('M d, Y') ?? 'Not yet submitted' }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Uploaded Documents -->
                        <div class="section-header mb-4 mt-5">
                            <h5 class="fw-semibold"><i class="fa fa-file-alt text-success me-2"></i> Uploaded Documents</h5>
                            <hr>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Document Type</th>
                                        <th>File Name</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($application->documents as $doc)
                                        <tr>
                                            <td>{{ $doc->document_type->label() }}</td>
                                            <td>{{ $doc->file_name }}</td>
                                            <td>{{ $doc->file_size_human }}</td>
                                            <td>
                                                @if($doc->verified)
                                                    <span class="badge bg-success">
                                                        <i class="fa fa-check me-1"></i> Verified
                                                    </span>
                                                @else
                                                    <span class="badge bg-secondary">
                                                        <i class="fa fa-clock me-1"></i> Pending
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Next Steps -->
                        @if($application->status === 'pending')
                            <div class="alert alert-info mt-5">
                                <h6 class="fw-semibold mb-2"><i class="fa fa-info-circle me-2"></i> What's Next?</h6>
                                <p class="mb-0">Our team is reviewing your application. This usually takes 2-3 business days. You will receive an email notification once the review is complete.</p>
                            </div>
                        @elseif($application->status === 'approved')
                            <div class="alert alert-success mt-5">
                                <h6 class="fw-semibold mb-2"><i class="fa fa-check-circle me-2"></i> Congratulations!</h6>
                                <p class="mb-2">Your KYC has been approved. Now you need to complete a 6-month testing period by:</p>
                                <ul class="mb-0">
                                    <li>Making purchases worth at least 10% of your desired credit amount</li>
                                    <li>Paying on time for 6 consecutive months</li>
                                </ul>
                            </div>
                        @endif

                        <div class="mt-5 text-center">
                            <a href="{{ route('home.store.products.index', ['store_slug' => $store->slug]) }}" class="btn btn-success btn-lg px-5">
                                <i class="fa fa-shopping-cart me-2"></i> Continue Shopping
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<br>
<br>
<br>
@endsection

@push('styles')
<style>
.status-page {
    background: #f8f9fa;
}
.status-card {
    background: #fff;
    border-radius: 18px;
    border: none;
}
.section-header h5 {
    color: #0D775E;
}
.detail-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    height: 100%;
}
.detail-item label {
    font-size: 0.85rem;
    margin-bottom: 5px;
}
.table th {
    background: #f8f9fa;
    color: #0D775E;
    font-weight: 600;
}
</style>
@endpush
