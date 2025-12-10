@extends('home.layout')
@section('title', 'Live First - KYC Application')

@section('content')
<br>
<br>
<br>
<br>
<div class="kyc-page py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="kyc-card shadow">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-5">
                            <span class="badge bg-success mb-3">Step 1 of 3</span>
                            <h2 class="fw-bold mb-3" style="color: #0D775E;">Complete Your KYC Application</h2>
                            <p class="text-muted">Please provide accurate information and upload all required documents</p>
                        </div>

                        @if ($errors->any())
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <strong>Please correct the following errors:</strong>
                                <ul class="mb-0 mt-2">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('home.live-first.kyc.submit', ['store_slug' => $store->slug]) }}" enctype="multipart/form-data">
                            @csrf

                            <!-- Personal Information Section -->
                            <div class="section-header mb-4">
                                <h5 class="fw-semibold"><i class="fa fa-user text-success me-2"></i> Personal Information</h5>
                                <hr>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control @error('full_name') is-invalid @enderror" value="{{ old('full_name', auth('customer')->user()->full_name) }}" required>
                                    @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" name="date_of_birth" class="form-control @error('date_of_birth') is-invalid @enderror" value="{{ old('date_of_birth') }}" required>
                                    @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                    <input type="tel" name="phone_number" class="form-control @error('phone_number') is-invalid @enderror" value="{{ old('phone_number', auth('customer')->user()->phone) }}" required>
                                    @error('phone_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <!-- Employment Information Section -->
                            <div class="section-header mb-4 mt-5">
                                <h5 class="fw-semibold"><i class="fa fa-briefcase text-success me-2"></i> Employment Information</h5>
                                <hr>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-8">
                                    <label class="form-label">Employer/Company Name <span class="text-danger">*</span></label>
                                    <input type="text" name="employer_name" class="form-control @error('employer_name') is-invalid @enderror" value="{{ old('employer_name') }}" required>
                                    @error('employer_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Years with Employer <span class="text-danger">*</span></label>
                                    <input type="number" step="0.1" name="years_with_employer" class="form-control @error('years_with_employer') is-invalid @enderror" value="{{ old('years_with_employer') }}" required>
                                    @error('years_with_employer')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <!-- Origin Information Section -->
                            <div class="section-header mb-4 mt-5">
                                <h5 class="fw-semibold"><i class="fa fa-map-marker-alt text-success me-2"></i> Origin Information</h5>
                                <hr>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">State of Origin <span class="text-danger">*</span></label>
                                    <input type="text" name="state_of_origin" class="form-control @error('state_of_origin') is-invalid @enderror" value="{{ old('state_of_origin') }}" required>
                                    @error('state_of_origin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">LGA of Origin <span class="text-danger">*</span></label>
                                    <input type="text" name="lga_of_origin" class="form-control @error('lga_of_origin') is-invalid @enderror" value="{{ old('lga_of_origin') }}" required>
                                    @error('lga_of_origin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Community</label>
                                    <input type="text" name="community" class="form-control @error('community') is-invalid @enderror" value="{{ old('community') }}">
                                    @error('community')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Village</label>
                                    <input type="text" name="village" class="form-control @error('village') is-invalid @enderror" value="{{ old('village') }}">
                                    @error('village')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <!-- Residential Information Section -->
                            <div class="section-header mb-4 mt-5">
                                <h5 class="fw-semibold"><i class="fa fa-home text-success me-2"></i> Residential Information</h5>
                                <hr>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Residential State <span class="text-danger">*</span></label>
                                    <input type="text" name="residential_state" class="form-control @error('residential_state') is-invalid @enderror" value="{{ old('residential_state') }}" required>
                                    @error('residential_state')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Residential LGA <span class="text-danger">*</span></label>
                                    <input type="text" name="residential_lga" class="form-control @error('residential_lga') is-invalid @enderror" value="{{ old('residential_lga') }}" required>
                                    @error('residential_lga')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Residential Address <span class="text-danger">*</span></label>
                                    <textarea name="residential_address" rows="3" class="form-control @error('residential_address') is-invalid @enderror" required>{{ old('residential_address') }}</textarea>
                                    @error('residential_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            <!-- Document Uploads Section -->
                            <div class="section-header mb-4 mt-5">
                                <h5 class="fw-semibold"><i class="fa fa-file-upload text-success me-2"></i> Document Uploads</h5>
                                <hr>
                            </div>

                            <div class="row g-4 mb-4">
                                @foreach($documentTypes as $key => $label)
                                    <div class="col-md-6">
                                        <div class="document-upload-card p-3 border rounded">
                                            <label class="form-label fw-semibold mb-2">
                                                {{ $label }} <span class="text-danger">*</span>
                                            </label>
                                            <input type="file" name="document_{{ $key }}" class="form-control @error('document_'.$key) is-invalid @enderror" accept="{{ $key === 'video' ? '.mp4,.mov,.avi' : ($key === 'selfie' ? '.jpg,.jpeg,.png' : '.pdf,.jpg,.jpeg,.png') }}" required>
                                            <small class="text-muted d-block mt-1">
                                                @if($key === 'video')
                                                    Max 100MB (MP4, MOV, AVI)
                                                @elseif($key === 'selfie')
                                                    Max 10MB (JPG, PNG)
                                                @else
                                                    Max 10MB (PDF, JPG, PNG)
                                                @endif
                                            </small>
                                            @error('document_'.$key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <!-- Terms and Submit -->
                            <div class="mt-5 pt-4 border-top">
                                <div class="form-contro mb-4">
                                    <input class="form-check-input" type="checkbox" id="terms" required>
                                    <label class="form-check-label" for="terms">
                                        I confirm that all information provided is accurate and I authorize automatic salary deduction for repayment
                                    </label>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="{{ route('home.live-first.index', ['store_slug' => $store->slug]) }}" class="btn btn-outline-secondary btn-lg px-5">
                                    Cancel
                                </a>
                                <button type="submit" id="submitBtn" class="btn btn-success btn-lg px-5">
                                    <i class="fa fa-paper-plane me-2"></i> Submit Application
                                </button>
                            </div>
                        </form>
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const submitBtn = document.getElementById('submitBtn');
    
    // File size limits in MB
    const fileSizeLimits = {
        'document_video': 100,
        'default': 10
    };
    
    // Get all file inputs
    const fileInputs = form.querySelectorAll('input[type="file"]');
    
    // Function to format file size
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
    
    // Function to validate file size
    function validateFileSize(input) {
        const file = input.files[0];
        if (!file) return true;
        
        const inputName = input.name;
        const maxSizeMB = fileSizeLimits[inputName] || fileSizeLimits['default'];
        const maxSizeBytes = maxSizeMB * 1024 * 1024;
        const fileSizeBytes = file.size;
        
        // Remove any existing error
        const existingError = input.parentElement.querySelector('.file-size-error');
        if (existingError) {
            existingError.remove();
        }
        
        if (fileSizeBytes > maxSizeBytes) {
            // Add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'file-size-error alert alert-danger mt-2 mb-0 py-2 small';
            errorDiv.innerHTML = `<i class="fa fa-exclamation-triangle me-1"></i> <strong>File too large!</strong> ${formatFileSize(fileSizeBytes)} exceeds the ${maxSizeMB}MB limit.`;
            input.parentElement.appendChild(errorDiv);
            return false;
        }
        
        return true;
    }
    
    // Function to check all files and enable/disable submit button
    function checkAllFiles() {
        let allValid = true;
        
        fileInputs.forEach(input => {
            if (input.files.length > 0) {
                if (!validateFileSize(input)) {
                    allValid = false;
                }
            }
        });
        
        // Enable/disable submit button
        submitBtn.disabled = !allValid;
        
        if (!allValid) {
            submitBtn.classList.add('disabled');
            submitBtn.title = 'Please fix file size errors before submitting';
        } else {
            submitBtn.classList.remove('disabled');
            submitBtn.title = '';
        }
        
        return allValid;
    }
    
    // Add change event listener to all file inputs
    fileInputs.forEach(input => {
        input.addEventListener('change', function() {
            checkAllFiles();
        });
    });
    
    // Validate on form submit
    form.addEventListener('submit', function(e) {
        if (!checkAllFiles()) {
            e.preventDefault();
            
            // Scroll to first error
            const firstError = document.querySelector('.file-size-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            // Show alert
            alert('Please fix the file size errors before submitting the form.');
            return false;
        }
        
        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Uploading...';
    });
});
</script>
@endpush

@push('styles')
<style>
.kyc-page {
    background: #f8f9fa;
}
.kyc-card {
    background: #fff;
    border-radius: 18px;
    border: none;
}
.section-header h5 {
    color: #0D775E;
}
.document-upload-card {
    background: #f8f9fa;
    transition: all 0.3s;
}
.document-upload-card:hover {
    background: #e9ecef;
    border-color: #0D775E !important;
}
.form-control:focus, .form-check-input:focus {
    border-color: #0D775E;
    box-shadow: 0 0 0 0.25rem rgba(13, 119, 94, 0.25);
}
.btn-success {
    background-color: #0D775E;
    border-color: #0D775E;
}
.btn-success:hover {
    background-color: #0a5f4a;
    border-color: #0a5f4a;
}
</style>
@endpush
