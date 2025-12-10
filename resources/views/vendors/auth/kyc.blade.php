@extends('vendors.auth.layout')

@section('title', 'Submit KYC Information')
@section('subtitle', 'Tell us about your business so we can verify your account')

@section('content')
    <div class="mb-4">
        <h5 class="fw-semibold mb-1 text-center">Hi, we would like to know a bit more about you</h5>
        <p class="text-center">Please provide the following information to complete your KYC process.</p>
    </div>

    <br>

    @if($application && $isKycSubmitted)
        <div class="alert alert-info" role="alert">
            We already have your KYC details and our team is reviewing them. You can update the form below if anything changed.
        </div>
    @endif

    <form method="POST" action="{{ route('vendor.kyc.submit', ['vendor' => $vendor]) }}" enctype="multipart/form-data" class="vstack gap-4">
        @csrf

        <div class="row g-3">
            <div class="col-lg-6">
                <label for="legal_name" class="form-label fw-semibold">Legal name</label>
                <input type="text" id="legal_name" name="legal_name"
                    class="form-control form-control-lg @error('legal_name') is-invalid @enderror"
                    value="{{ old('legal_name', $vendor->name) }}" placeholder="Full name" required>
                @error('legal_name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-6">
                <label for="phone_number" class="form-label fw-semibold">Phone number</label>
                <input type="text" id="phone_number" name="phone_number"
                    class="form-control form-control-lg @error('phone_number') is-invalid @enderror"
                    value="{{ old('phone_number', $vendor->phone) }}" placeholder="eg: +234 81 000 000 00" required>
                @error('phone_number')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-6">
                <label for="date_of_birth" class="form-label fw-semibold">Date of birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth"
                    class="form-control form-control-lg @error('date_of_birth') is-invalid @enderror"
                    value="{{ old('date_of_birth', optional($application->date_of_birth ?? null)->format('Y-m-d')) }}" required>
                @error('date_of_birth')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-lg-6">
                <label for="country" class="form-label fw-semibold">Country</label>
                <select id="country" name="country"
                    class="form-select form-select-lg @error('country') is-invalid @enderror"
                    data-selected="{{ $selectedCountry }}"
                    required>
                    <option value="" @selected(!$selectedCountry) disabled>Select country</option>
                    @foreach($countryOptions as $country)
                        <option value="{{ $country }}" @selected($country === $selectedCountry)>{{ $country }}</option>
                    @endforeach
                    @unless(!$selectedCountry || $countryOptions->contains($selectedCountry))
                        <option value="{{ $selectedCountry }}" selected>{{ $selectedCountry }}</option>
                    @endunless
                </select>
                @error('country')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12">
                <label for="address_line" class="form-label fw-semibold">Address</label>
                <input type="text" id="address_line" name="address_line"
                    class="form-control form-control-lg @error('address_line') is-invalid @enderror"
                    value="{{ old('address_line', $application->address_line ?? '') }}" placeholder="eg: 12 clifford street" required>
                @error('address_line')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-4">
                <label for="city" class="form-label fw-semibold">City / Area</label>
                <input type="text" id="city" name="city"
                    class="form-control form-control-lg @error('city') is-invalid @enderror"
                    value="{{ old('city', $application->city ?? '') }}" placeholder="eg: Lekki" required>
                @error('city')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-4">
                <label for="state" class="form-label fw-semibold">State / Region</label>
                <select id="state" name="state"
                    class="form-select form-select-lg @error('state') is-invalid @enderror"
                    data-selected="{{ $selectedState }}"
                    required>
                    <option value="" @selected(!$selectedState) disabled>Select state / region</option>
                    @foreach($stateOptions as $state)
                        <option value="{{ $state }}" @selected($state === $selectedState)>{{ $state }}</option>
                    @endforeach
                    @unless(!$selectedState || $stateOptions->contains($selectedState))
                        <option value="{{ $selectedState }}" selected>{{ $selectedState }}</option>
                    @endunless
                </select>
                @error('state')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-4">
                <label for="kyc_document_type_id" class="form-label fw-semibold">Identification document type</label>
                <select id="kyc_document_type_id" name="kyc_document_type_id"
                    class="form-select form-select-lg @error('kyc_document_type_id') is-invalid @enderror"
                    required>
                    <option value="" @selected(!$selectedDocumentTypeId) disabled>Select document type</option>
                    @foreach($documentTypes as $documentType)
                        <option value="{{ $documentType->id }}" @selected((int) $selectedDocumentTypeId === $documentType->id)>{{ $documentType->name }}</option>
                    @endforeach
                </select>
                @error('kyc_document_type_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-4">
                <label for="kyc_document_id" class="form-label fw-semibold">Document ID number</label>
                <input type="text" id="kyc_document_id" name="kyc_document_id"
                    class="form-control form-control-lg @error('kyc_document_id') is-invalid @enderror"
                    value="{{ $documentIdValue }}" required>
                @error('kyc_document_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-8">
                <label for="identification_document" class="form-label fw-semibold">Identification document</label>
                <input type="file" id="identification_document" name="identification_document"
                    class="form-control form-control-lg @error('identification_document') is-invalid @enderror" accept=".jpg,.jpeg,.png,.pdf">
                @error('identification_document')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                @if($application && $application->identification_document_path)
                    <div class="mt-2 small">
                        Current document:
                        <a class="link-primary" href="{{ asset('storage/' . $application->identification_document_path) }}" target="_blank" rel="noopener">
                            View uploaded file
                        </a>
                    </div>
                @endif
            </div>

            <div class="col-md-12">
                <label for="selfie_image" class="form-label fw-semibold">Selfie image</label>
                <input type="file" id="selfie_image" name="selfie_image"
                    class="form-control form-control-lg @error('selfie_image') is-invalid @enderror" accept=".jpg,.jpeg,.png">
                @error('selfie_image')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                @if($application && $application->selfie_image_path)
                    <div class="mt-2 small">
                        Current selfie:
                        <a class="link-primary" href="{{ asset('storage/' . $application->selfie_image_path) }}" target="_blank" rel="noopener">
                            View uploaded photo
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-100">Submit for review</button>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const routeTree = @json($routeTree);
            const countrySelect = document.getElementById('country');
            const stateSelect = document.getElementById('state');

            const resetSelect = (selectEl, placeholder) => {
                selectEl.innerHTML = '';
                const option = document.createElement('option');
                option.value = '';
                option.textContent = placeholder;
                option.disabled = true;
                option.selected = true;
                selectEl.appendChild(option);
                selectEl.disabled = true;
            };

            const populateSelect = (selectEl, values, selectedValue, placeholder) => {
                resetSelect(selectEl, placeholder);
                const entries = Array.isArray(values)
                    ? values
                    : Object.values(values ?? {});

                if (!entries || entries.length === 0) {
                    return;
                }

                entries.forEach(value => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    if (value === selectedValue) {
                        option.selected = true;
                        selectEl.disabled = false;
                    }
                    selectEl.appendChild(option);
                });

                if (selectEl.options.length > 1) {
                    selectEl.disabled = false;
                }
            };

            const updateStates = (selectedState = stateSelect.dataset.selected ?? '') => {
                const country = countrySelect.value;
                const stateCollection = routeTree[country] ?? {};
                const states = Object.keys(stateCollection);
                populateSelect(stateSelect, states, selectedState, 'Select state / region');
                if (!states.includes(selectedState)) {
                    selectedState = '';
                }
                stateSelect.dispatchEvent(new Event('change'));
            };

            countrySelect.addEventListener('change', () => {
                stateSelect.dataset.selected = '';
                updateStates('');
            });

            updateStates();
        });
    </script>
@endsection
