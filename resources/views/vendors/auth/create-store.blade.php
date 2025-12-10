@extends('vendors.auth.layout')

@section('title', 'Create your store')

@section('content')
    <div class="mb-4 text-center">
        <h3 class="fw-semibold mb-1">Almost there! Let’s set up your store</h3>
        <p class="text-muted mb-0">Complete the details below so we can prepare your storefront while our team reviews your KYC.</p>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form action="{{ route('vendor.kyc.store.submit', ['vendor' => $vendor]) }}" method="POST" enctype="multipart/form-data" class="vstack gap-4">
                @csrf

                <div class="row g-4">
                    <div class="col-12">
                        <label for="name" class="form-label fw-semibold">Store name<span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name"
                               class="form-control form-control-lg @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="eg: Swift Essentials" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label fw-semibold">About your store</label>
                        <textarea id="description" name="description" rows="3"
                                  class="form-control form-control-lg @error('description') is-invalid @enderror"
                                  placeholder="Tell customers what you sell and what makes your brand special">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="support_email" class="form-label fw-semibold">Support email</label>
                        <input type="email" id="support_email" name="support_email"
                               class="form-control form-control-lg @error('support_email') is-invalid @enderror"
                               value="{{ old('support_email') }}" placeholder="support@yourbrand.com">
                        @error('support_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="support_phone" class="form-label fw-semibold">Support phone</label>
                        <input type="text" id="support_phone" name="support_phone"
                               class="form-control form-control-lg @error('support_phone') is-invalid @enderror"
                               value="{{ old('support_phone') }}" placeholder="+1 800 000 0000">
                        @error('support_phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-12">
                        <label for="address" class="form-label fw-semibold">Store address</label>
                        <input type="text" id="address" name="address"
                               class="form-control form-control-lg @error('address') is-invalid @enderror"
                               value="{{ old('address') }}" placeholder="eg: 12 Clifford Street" />
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <label for="ownership_type_id" class="form-label fw-semibold">Ownership type</label>
                        <select id="ownership_type_id" name="ownership_type_id" class="form-select form-select-lg @error('ownership_type_id') is-invalid @enderror">
                            <option value="" @selected(!old('ownership_type_id')) disabled>Select ownership type</option>
                            @foreach($ownershipTypes as $type)
                                <option value="{{ $type->id }}" @selected(old('ownership_type_id') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @error('ownership_type_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="business_type_id" class="form-label fw-semibold">Business type</label>
                        <select id="business_type_id" name="business_type_id" class="form-select form-select-lg @error('business_type_id') is-invalid @enderror">
                            <option value="" @selected(!old('business_type_id')) disabled>Select business type</option>
                            @foreach($businessTypes as $type)
                                <option value="{{ $type->id }}" @selected(old('business_type_id') == $type->id)>{{ $type->name }}</option>
                            @endforeach
                        </select>
                        @error('business_type_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <label for="instagram_url" class="form-label fw-semibold">Instagram</label>
                        <input type="url" id="instagram_url" name="instagram_url"
                               class="form-control form-control-lg @error('instagram_url') is-invalid @enderror"
                               value="{{ old('instagram_url') }}" placeholder="https://www.instagram.com/yourhandle">
                        @error('instagram_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="facebook_url" class="form-label fw-semibold">Facebook</label>
                        <input type="url" id="facebook_url" name="facebook_url"
                               class="form-control form-control-lg @error('facebook_url') is-invalid @enderror"
                               value="{{ old('facebook_url') }}" placeholder="https://www.facebook.com/yourpage">
                        @error('facebook_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <label for="twitter_url" class="form-label fw-semibold">Twitter / X</label>
                        <input type="url" id="twitter_url" name="twitter_url"
                               class="form-control form-control-lg @error('twitter_url') is-invalid @enderror"
                               value="{{ old('twitter_url') }}" placeholder="https://twitter.com/yourhandle">
                        @error('twitter_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="tiktok_url" class="form-label fw-semibold">TikTok</label>
                        <input type="url" id="tiktok_url" name="tiktok_url"
                               class="form-control form-control-lg @error('tiktok_url') is-invalid @enderror"
                               value="{{ old('tiktok_url') }}" placeholder="https://www.tiktok.com/@yourhandle">
                        @error('tiktok_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="logo" class="form-label fw-semibold">Store logo</label>
                    <div class="border rounded-3 p-4 d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3">
                        <div class="border rounded-3" style="width: 160px; height: 90px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#f4f4f5;">
                            <img id="logoPreview" src="#" alt="Logo preview" style="max-width:100%; max-height:100%; display:none;">
                        </div>
                        <div class="flex-grow-1">
                            <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp"
                                   class="form-control form-control-lg @error('logo') is-invalid @enderror">
                            <small class="text-muted d-block mt-2">PNG, JPG, or WEBP. Max 2MB.</small>
                            @error('logo')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-dark btn-lg px-4">Save &amp; continue</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const logoInput = document.getElementById('logo');
            const preview = document.getElementById('logoPreview');
            if (!logoInput || !preview) return;

            logoInput.addEventListener('change', function (event) {
                const file = event.target.files?.[0];
                if (!file) {
                    preview.style.display = 'none';
                    preview.src = '#';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function (e) {
                    preview.src = e.target?.result ?? '#';
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            });
        });
    </script>
@endsection
