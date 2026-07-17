<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Management\SubmitKycRequest;
use App\Mail\AdminKycSubmitted;
use App\Mail\VendorKycSubmitted;
use App\Models\DeliveryRoute;
use App\Models\KycDocumentType;
use App\Models\Store;
use App\Models\User;
use App\Models\KycApplication;
use App\Models\OwnershipType;
use App\Models\BusinessType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class KycController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $kycApp = $user->kycApplication;
        $documentTypes = KycDocumentType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'KYC Verification'],
        ];

        return view('management.kyc', compact(
            'user', 'kycApp', 'documentTypes', 'breadcrumbs'
        ));
    }

    public function submit(SubmitKycRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        

        if (!$user->is_verified) {
            return redirect()->route('management.auth.verify-otp')
                ->with('warning', 'Verify your email before submitting your KYC information.');
        }

        $kyc = $user->kycApplication;
        $kycStatus = $kyc?->status;

        if ($kycStatus === KycApplication::STATUS_SUBMITTED) {
            return back()->with('warning', 'Your KYC is currently under review.');
        }

        if ($kycStatus === KycApplication::STATUS_APPROVED) {
            return back()->with('info', 'Your KYC has already been approved.');
        }

        $data = $request->validated();

        Log::info('vendor.kyc.submission_received', [
            'user_id' => $user->id,
            'account_id' => $user->account_id,
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

        $application = new KycApplication();

        if ($request->hasFile('identification_document')) {
            if ($application->identification_document_path) {
                try {
                    Storage::disk('public')->delete($application->identification_document_path);
                } catch (\Throwable $e) {
                    Log::warning('vendor.kyc.delete_old_document_failed', [
                        'user_id' => $user->id,
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
                        'user_id' => $user->id,
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
            'status' => KycApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'approved_at' => null,
            'rejected_at' => null,
            'review_notes' => null,
        ]);

        $application->forceFill(['user_id' => $user->id]);
        $application->save();

        $user->forceFill([
            'status' => 'pending',
        ])->save();

        Log::info('vendor.kyc.submitted', [
            'user_id' => $user->id,
            'application_id' => $application->id,
        ]);

        $this->queueVendorNotification($user, $application);
        $this->queueAdminNotification($application);

        return redirect()->route('management.kyc.show')
            ->with('success', 'KYC submitted successfully! We\'ll review your documents and notify you within 1-2 business days.');
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

    private function queueVendorNotification(User $user, KycApplication $application): void
    {
        if (empty($user->email)) {
            return;
        }

        try {
            Mail::to($user->email)->queue(new VendorKycSubmitted($user, $application));
            Log::info('vendor.kyc.vendor_mail_queued', [
                'user_id' => $user->id,
                'application_id' => $application->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('vendor.kyc.vendor_mail_queue_failed', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function queueAdminNotification(KycApplication $application): void
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
