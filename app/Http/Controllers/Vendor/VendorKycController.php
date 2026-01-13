<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\Auth\SubmitKycRequest;
use App\Mail\AdminKycSubmitted;
use App\Mail\VendorKycSubmitted;
use App\Models\DeliveryRoute;
use App\Models\KycDocumentType;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorKycApplication;
use App\Models\OwnershipType;
use App\Models\BusinessType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use App\Mail\VendorStoreCreated;
use App\Mail\AdminStoreCreated;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VendorKycController extends Controller
{
    public function show(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');
        Log::info('vendor.kyc.show_requested', [
            'route_vendor_account_id' => $routeVendor->account_id,
            'route_path' => $request->path(),
            'vendor_account_id' => $vendor?->account_id,
        ]);

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            Log::error('vendor.kyc.show_mismatch', [
                'route_vendor_account_id' => $routeVendor->account_id,
                'vendor_account_id' => $vendor?->account_id,
            ]);
            abort(503, 'Unable to verify your access to the KYC page.');
        }

        $existingApplication = $vendor->kycApplication;
        if ($existingApplication) {
            return redirect()->route('vendor.dashboard')
                ->with('status', 'Your KYC is already in progress.');
        }

        $application = new VendorKycApplication();

        $routes = DeliveryRoute::query()
            ->select('country', 'state', 'area')
            ->where('active', true)
            ->orderBy('country')
            ->orderBy('state')
            ->orderBy('area')
            ->get()
            ->groupBy('country')
            ->map(function ($states) {
                return $states->groupBy('state')->map(function ($areas) {
                    return $areas->pluck('area')->unique()->sort()->values();
                })->sortKeys();
            })->sortKeys();

        $routeTree = $routes
            ->map(fn ($states) => $states->map(fn ($areas) => $areas->values()->toArray())->toArray())
            ->toArray();

        $countryOptions = $routes->keys();

        $documentTypes = KycDocumentType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedCountry = old('country', $application->country ?? '');
        if (!$selectedCountry && $countryOptions->count() === 1) {
            $selectedCountry = $countryOptions->first();
        }

        $stateOptions = collect();
        if ($selectedCountry && $routes->has($selectedCountry)) {
            $stateOptions = $routes->get($selectedCountry)->keys();
        }

        $selectedState = old('state', $application->state ?? '');
        if (!$selectedState && $stateOptions->count() === 1) {
            $selectedState = $stateOptions->first();
        }

        $selectedDocumentTypeId = old('kyc_document_type_id', $application->kyc_document_type_id ?? $vendor->kyc_document_type_id ?? '');
        $documentIdValue = old('kyc_document_id', $application->kyc_document_id ?? $vendor->kyc_document_id ?? '');

        if ($routes->isEmpty()) {
            Log::warning('vendor.kyc.routes_missing', [
                'vendor_id' => $vendor->id,
            ]);
        } else {
            Log::info('vendor.kyc.routes_loaded', [
                'vendor_id' => $vendor->id,
                'countries_count' => $countryOptions->count(),
                'selected_country' => $selectedCountry ?: null,
                'state_options_count' => $stateOptions->count(),
                'selected_state' => $selectedState ?: null,
                'document_types_count' => $documentTypes->count(),
                'selected_document_type_id' => $selectedDocumentTypeId ?: null,
                'route_tree_snapshot' => array_key_exists($selectedCountry, $routeTree)
                    ? array_keys($routeTree[$selectedCountry])
                    : [],
            ]);
        }

        $isKycSubmitted = $vendor->kycApplication?->status === VendorKycApplication::STATUS_SUBMITTED;

        return view('vendors.auth.kyc', compact(
            'vendor',
            'application',
            'routes',
            'routeTree',
            'selectedCountry',
            'selectedState',
            'selectedDocumentTypeId',
            'documentIdValue',
            'countryOptions',
            'stateOptions',
            'documentTypes'
            , 'isKycSubmitted'
        ));
    }

    public function submit(SubmitKycRequest $request, Vendor $routeVendor): RedirectResponse
    {
        /** @var Vendor $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            Log::error('vendor.kyc.submit_mismatch', [
                'route_vendor_account_id' => $routeVendor->account_id,
                'vendor_account_id' => $vendor?->account_id,
            ]);
            abort(503, 'Unable to verify your access to submit KYC information.');
        }

        if (!$vendor->is_verified) {
            return redirect()->route('vendor.auth.verify-otp')
                ->with('warning', 'Verify your email before submitting your KYC information.');
        }

        $kycStatus = $vendor->kycApplication?->status;
        if ($kycStatus === VendorKycApplication::STATUS_SUBMITTED) {
            return back()->with('warning', 'Your KYC is currently under review. We will notify you once it is processed.');
        }

        if ($kycStatus === VendorKycApplication::STATUS_APPROVED) {
            return redirect()->route('vendor.dashboard')
                ->with('status', 'Your KYC has already been approved.');
        }

        if ($vendor->kycApplication()->exists()) {
            return redirect()->route('vendor.dashboard')
                ->with('status', 'Your KYC is already in progress.');
        }

        $data = $request->validated();

        Log::info('vendor.kyc.submission_received', [
            'vendor_id' => $vendor->id,
            'account_id' => $vendor->account_id,
            'data' => [
                'legal_name' => $data['legal_name'],
                'phone_number' => $data['phone_number'],
                'date_of_birth' => $data['date_of_birth'],
                'address_line' => $data['address_line'],
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'],
                'kyc_document_type_id' => $data['kyc_document_type_id'],
                'kyc_document_id' => $data['kyc_document_id'],
            ],
        ]);

        $application = new VendorKycApplication();

        if ($request->hasFile('identification_document')) {
            if ($application->identification_document_path) {
                try {
                    Storage::disk('public')->delete($application->identification_document_path);
                } catch (\Throwable $e) {
                    Log::warning('vendor.kyc.delete_old_document_failed', [
                        'vendor_id' => $vendor->id,
                        'path' => $application->identification_document_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $application->identification_document_path = $request->file('identification_document')
                ->store('kyc/documents', 'public');
        }

        if ($request->hasFile('selfie_image')) {
            if ($application->selfie_image_path) {
                try {
                    Storage::disk('public')->delete($application->selfie_image_path);
                } catch (\Throwable $e) {
                    Log::warning('vendor.kyc.delete_old_selfie_failed', [
                        'vendor_id' => $vendor->id,
                        'path' => $application->selfie_image_path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $application->selfie_image_path = $request->file('selfie_image')
                ->store('kyc/selfies', 'public');
        }

        $userAgent = (string) $request->userAgent();

        $application->fill([
            'legal_name' => $data['legal_name'],
            'phone_number' => $data['phone_number'],
            'date_of_birth' => $data['date_of_birth'],
            'address_line' => $data['address_line'],
            'city' => $data['city'],
            'state' => $data['state'],
            'country' => $data['country'],
            'kyc_document_type_id' => $data['kyc_document_type_id'],
            'kyc_document_id' => $data['kyc_document_id'],
            'selfie_image_path' => $application->selfie_image_path,
            'device_type' => $this->detectDeviceType($userAgent),
            'browser' => Str::limit($userAgent, 255),
            'ip_address' => $request->ip(),
        ]);

        $application->forceFill([
            'status' => VendorKycApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'approved_at' => null,
            'rejected_at' => null,
            'review_notes' => null,
        ]);

        $application->vendor()->associate($vendor);
        $application->save();

        $vendor->forceFill([
            'status' => Vendor::STATUS_PENDING,
        ])->save();

        Log::info('vendor.kyc.submitted', [
            'vendor_id' => $vendor->id,
            'application_id' => $application->id,
        ]);

        $this->queueVendorNotification($vendor, $application);
        $this->queueAdminNotification($application);

        return redirect()->route('vendor.store.create', ['vendor' => $vendor])
            ->with('success', 'KYC submitted successfully! Continue setting up your store while we review your details.');
    }

    private function detectDeviceType(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        if ($ua === '') {
            return 'unknown';
        }

        return match (true) {
            str_contains($ua, 'tablet') || str_contains($ua, 'ipad') => 'tablet',
            str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android') => 'mobile',
            default => 'desktop',
        };
    }

    private function queueVendorNotification(Vendor $vendor, VendorKycApplication $application): void
    {
        if (empty($vendor->email)) {
            return;
        }

        try {
            Mail::to($vendor->email)->queue(new VendorKycSubmitted($vendor, $application));
            Log::info('vendor.kyc.vendor_mail_queued', [
                'vendor_id' => $vendor->id,
                'application_id' => $application->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('vendor.kyc.vendor_mail_queue_failed', [
                'vendor_id' => $vendor->id,
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function queueAdminNotification(VendorKycApplication $application): void
    {
        $admins = $this->adminRecipients();
        if (empty($admins)) {
            Log::warning('vendor.kyc.admin_mail_skipped', [
                'application_id' => $application->id,
                'reason' => 'no_admin_recipients',
            ]);
            return;
        }

        try {
            Mail::to($admins)->queue(new AdminKycSubmitted($application));
            Log::info('vendor.kyc.admin_mail_queued', [
                'application_id' => $application->id,
                'admin_count' => count($admins),
            ]);
        } catch (\Throwable $e) {
            Log::error('vendor.kyc.admin_mail_queue_failed', [
                'application_id' => $application->id,
                'admin_count' => count($admins),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function adminRecipients(): array
    {
        $emails = User::where('role', 'superadmin')->pluck('email')->filter()->all();

        if (empty($emails) && config('mail.from.address')) {
            $emails = [config('mail.from.address')];
        }

        return $emails;
    }
}
