<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\SendKycPhoneOtpRequest;
use App\Http\Requests\Management\SubmitKycRequest;
use App\Http\Requests\Management\VerifyKycPhoneOtpRequest;
use App\Mail\AdminKycSubmitted;
use App\Mail\BusinessKycSubmitted;
use App\Models\KycApplication;
use App\Models\KycDocumentType;
use App\Models\Store;
use App\Models\User;
use App\Services\Management\KycPhoneVerificationService;
use App\Support\Sms\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WS-10 — business KYC submission and status.
 *
 * Mirrors management.kyc.show / management.kyc.submit: one application row per
 * submission (history is retained, the latest drives the screen), the owner's
 * user status flips to `pending` while under review, both notification mails
 * are queued with failure isolation, and files land under kyc/documents and
 * kyc/selfies on the public disk.
 *
 * Approve/reject lives in the admin audience (storify-admin WS3) and is what
 * flips an application out of `submitted` — the store-activation gate in
 * StoreLifecycleController@activate already refuses until it is `approved`.
 */
class KycController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly KycPhoneVerificationService $phones) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->authorizeOwner($request);
        $application = $this->latestApplication($user);

        $documentTypes = KycDocumentType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (KycDocumentType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'description' => $type->description,
            ])
            ->all();

        // Prior submissions, newest first: legacy kept every row and showed
        // only the latest, so a resubmitted user lost sight of the earlier
        // rejection reason. Surfacing the history is the improvement.
        $history = $this->applicationQuery($user)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (KycApplication $application) => $this->applicationPayload($application, false))
            ->all();

        $blockReason = $this->blockReason($user, $application);

        return $this->ok([
            'application' => $this->applicationPayload($application),
            'history' => $history,
            'document_types' => $documentTypes,
            'state' => $this->state($application),
            'can_submit' => $blockReason === null,
            'block_reason' => $blockReason,
            'phone_verification' => $this->phoneVerificationPayload($user, $application),
            'gate' => $this->gate($user, $application),
        ]);
    }

    /**
     * Send the code that proves the KYC phone belongs to the owner.
     *
     * Synchronous, unlike every alert: the person is waiting on this, and a
     * Termii refusal has to reach them as an error they can retry now.
     */
    public function sendPhoneOtp(SendKycPhoneOtpRequest $request): JsonResponse
    {
        $user = $this->authorizeOwner($request);

        if (! $user->business_id) {
            return $this->error('Complete your business setup before verifying your phone number.', 422);
        }

        $data = $request->validated();

        $result = $this->phones->send(
            $user,
            $data['phone_country_code'] ?? null,
            $data['phone_number'],
        );

        return $this->ok($result, 'Verification code sent by SMS.');
    }

    public function verifyPhoneOtp(VerifyKycPhoneOtpRequest $request): JsonResponse
    {
        $user = $this->authorizeOwner($request);

        if (! $user->business_id) {
            return $this->error('Complete your business setup before verifying your phone number.', 422);
        }

        $data = $request->validated();

        $this->phones->confirm(
            $user,
            $data['phone_country_code'] ?? null,
            $data['phone_number'],
            $data['code'],
        );

        $application = $this->latestApplication($user);
        $blockReason = $this->blockReason($user, $application);

        return $this->ok([
            'phone_verification' => $this->phoneVerificationPayload($user, $application),
            'application' => $this->applicationPayload($application),
            'state' => $this->state($application),
            'can_submit' => $blockReason === null,
            'block_reason' => $blockReason,
        ], 'Phone number verified.');
    }

    public function store(SubmitKycRequest $request): JsonResponse
    {
        $user = $this->authorizeOwner($request);

        // Legacy bounced unverified owners to OTP; as JSON that is a 403 the
        // SPA routes on.
        if (! $user->is_verified) {
            return $this->error('Verify your email before submitting your KYC information.', 403);
        }

        if (! $user->business_id) {
            return $this->error('Complete your business setup before submitting your KYC information.', 422);
        }

        $data = $request->validated();
        $previous = $this->latestApplication($user);

        // Submission state machine: `submitted` and `approved` block, a
        // rejected (or absent) application may resubmit.
        if ($previous?->status === KycApplication::STATUS_SUBMITTED) {
            return $this->error('Your KYC is currently under review.', 422);
        }

        if ($previous?->status === KycApplication::STATUS_APPROVED) {
            return $this->error('Your KYC has already been approved.', 422);
        }

        // Legacy only checked `exists`, so a form left open across a document
        // type being retired could still submit it. Active-only now.
        if (! KycDocumentType::query()->where('is_active', true)->whereKey($data['kyc_document_type_id'])->exists()) {
            throw ValidationException::withMessages([
                'kyc_document_type_id' => 'The selected document type is no longer available.',
            ]);
        }

        // Derived once, before the transaction, so the log line, the stored row
        // and the review queue all name the applicant the same way.
        $legalName = $request->legalName();
        $dialCode = $request->dialCode();

        // The form's phone must be one this owner proved they control. Checked
        // against the normalised number, not the typed string, so 0803… and
        // +234803… are the same phone — and required here as well as in the
        // payload, because a stale form could otherwise post without ever
        // asking for a code.
        $submittedPhone = PhoneNumber::normalize($dialCode, $data['phone_number'], SubmitKycRequest::DEFAULT_DIAL_CODE);

        if ($submittedPhone === null) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid phone number.',
            ]);
        }

        $phoneVerification = $this->phones->verificationFor($user, $submittedPhone);

        if ($phoneVerification === null) {
            throw ValidationException::withMessages([
                'phone_number' => 'Verify this phone number by SMS before submitting your KYC.',
            ]);
        }

        Log::info('business.kyc.submission_received', [
            'user_id' => $user->id,
            'data' => [
                'legal_name' => $legalName,
                'phone_number' => $data['phone_number'],
                'date_of_birth' => $data['date_of_birth'],
                'address_line' => $data['address_line'],
                'city' => $data['city'],
                'state' => $data['state'],
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $data['country'],
                'kyc_document_type_id' => $data['kyc_document_type_id'],
                'kyc_document_id' => $data['kyc_document_id'],
            ],
        ]);

        $files = [];
        $superseded = [];

        try {
            if ($request->hasFile('identification_document')) {
                $files['identification_document_path'] = $request->file('identification_document')
                    ->store('kyc/documents', 'public');
                $superseded['identification_document_path'] = $previous?->identification_document_path;
            }

            if ($request->hasFile('selfie_image')) {
                $files['selfie_image_path'] = $request->file('selfie_image')
                    ->store('kyc/selfies', 'public');
                $superseded['selfie_image_path'] = $previous?->selfie_image_path;
            }

            $userAgent = (string) $request->userAgent();
            $ipAddress = $request->ip();

            $application = DB::transaction(function () use ($user, $data, $files, $previous, $userAgent, $ipAddress, $legalName, $dialCode, $phoneVerification) {
                $application = new KycApplication;

                // `fill`, not `forceFill`. The model's $fillable was missing
                // business_id, kyc_document_id and the three machine-captured
                // columns, so this used to step around it — which is how legacy
                // ended up "capturing" device metadata that never reached the
                // row. The list is complete now, so anything that is still
                // dropped from here is genuinely not assignable.
                $application->fill([
                    'user_id' => $user->id,
                    'business_id' => $user->business_id,
                    'legal_name' => $legalName,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'phone_number' => $data['phone_number'],
                    'phone_country_code' => $dialCode,
                    'phone_verified_at' => $phoneVerification->verified_at,
                    'date_of_birth' => $data['date_of_birth'],
                    'address_line' => $data['address_line'],
                    'city' => $data['city'],
                    'state' => $data['state'],
                    'postal_code' => $data['postal_code'] ?? null,
                    'country' => $data['country'],
                    'kyc_document_type_id' => $data['kyc_document_type_id'],
                    'kyc_document_id' => $data['kyc_document_id'],
                    'identification_document_path' => $files['identification_document_path'] ?? null,
                    'selfie_image_path' => $files['selfie_image_path'] ?? null,
                    'device_type' => $this->detectDeviceType($userAgent),
                    'browser' => Str::limit($userAgent, 255),
                    'ip_address' => $ipAddress,
                    // New row per submission; the previous application is the
                    // history and keeps its own review outcome.
                    'status' => KycApplication::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'approved_at' => null,
                    'rejected_at' => null,
                    'review_notes' => null,
                ])->save();

                // A replaced file is cleared from the superseded row so the
                // database never points at a file the post-commit cleanup
                // removes from disk.
                if ($previous) {
                    $cleared = [];

                    if (array_key_exists('identification_document_path', $files)) {
                        $cleared['identification_document_path'] = null;
                    }

                    if (array_key_exists('selfie_image_path', $files)) {
                        $cleared['selfie_image_path'] = null;
                    }

                    if ($cleared !== []) {
                        $previous->forceFill($cleared)->save();
                    }
                }

                $user->forceFill(['status' => 'pending'])->save();

                return $application;
            });
        } catch (\Throwable $e) {
            // Nothing is left behind if the row could not be written.
            foreach ($files as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        // Legacy's old-file deletion existed but never fired (it checked a
        // freshly constructed model, whose paths are always null), so every
        // resubmission orphaned its predecessor's uploads. Deleted after
        // commit, and only for files the new submission actually replaced.
        foreach ($superseded as $path) {
            if (! $path) {
                continue;
            }

            try {
                Storage::disk('public')->delete($path);
                Log::info('business.kyc.superseded_file_deleted', [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'path' => $path,
                ]);
            } catch (\Throwable $e) {
                Log::warning('business.kyc.superseded_file_delete_failed', [
                    'user_id' => $user->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('business.kyc.submitted', [
            'user_id' => $user->id,
            'application_id' => $application->id,
        ]);

        $this->queueBusinessNotification($user, $application);
        $this->queueAdminNotification($application);

        return $this->ok(
            [
                'application' => $this->applicationPayload($application->fresh()),
                'state' => $this->state($application),
                'can_submit' => false,
                'block_reason' => 'under_review',
                'gate' => $this->gate($user, $application),
            ],
            "KYC submitted successfully! We'll review your documents and notify you within 1-2 business days.",
            201,
        );
    }

    /**
     * The owner's own applications, scoped to their current business.
     *
     * Rows with a null business_id are kept reachable: the column was added
     * after the table (and the legacy controller's fill() silently dropped
     * it), so an owner's existing application predates it. The user_id is the
     * real tenant boundary for KYC anyway.
     */
    private function applicationQuery(User $user)
    {
        return KycApplication::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->whereNull('business_id')
                ->orWhere('business_id', $user->business_id));
    }

    /**
     * The owner's own latest application, scoped to their current business.
     */
    private function latestApplication(User $user): ?KycApplication
    {
        return $this->applicationQuery($user)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * KYC is the owner's identity, so only the owner may read or submit it —
     * legacy hid the sidebar entry from staff but left the route reachable.
     */
    private function authorizeOwner(Request $request): User
    {
        $user = $this->user($request);

        if (! $user->isBusinessOwner()) {
            abort(403, 'KYC verification can only be managed by the business owner.');
        }

        return $user;
    }

    private function state(?KycApplication $application): string
    {
        return match ($application?->status) {
            KycApplication::STATUS_SUBMITTED => 'submitted',
            KycApplication::STATUS_APPROVED => 'approved',
            KycApplication::STATUS_REJECTED => 'rejected',
            default => 'required',
        };
    }

    private function blockReason(User $user, ?KycApplication $application): ?string
    {
        if (! $user->is_verified) {
            return 'email_unverified';
        }

        if (! $user->business_id) {
            return 'no_business';
        }

        // An application already in the review queue, or approved, is not
        // something a phone check can change. Those states keep the reason they
        // have always had; the phone becomes the blocker only when the owner
        // could otherwise submit.
        if ($application?->status === KycApplication::STATUS_SUBMITTED) {
            return 'under_review';
        }

        if ($application?->status === KycApplication::STATUS_APPROVED) {
            return 'approved';
        }

        if (! $this->phones->hasVerifiedPhone($user)) {
            return 'phone_unverified';
        }

        return null;
    }

    /**
     * @return array{phone: string|null, verified: bool, verified_at: string|null}
     */
    private function phoneVerificationPayload(User $user, ?KycApplication $application): array
    {
        if ($application !== null && filled($application->phone_number)) {
            $phone = PhoneNumber::normalize(
                $application->phone_country_code,
                $application->phone_number,
                SubmitKycRequest::DEFAULT_DIAL_CODE,
            );

            $verification = $phone === null ? null : $this->phones->verificationFor($user, $phone);

            return [
                'phone' => $phone,
                'verified' => $verification !== null,
                'verified_at' => $verification?->verified_at?->toISOString(),
            ];
        }

        $latest = $this->phones->latestVerification($user);

        return [
            'phone' => $latest?->phone,
            'verified' => $latest !== null,
            'verified_at' => $latest?->verified_at?->toISOString(),
        ];
    }

    /**
     * Data the dashboard/storefront banners need. Legacy counted
     * ['pending', 'inactive'] stores; the new enum calls the second one
     * suspended, and both are the stores an approved KYC unlocks.
     *
     * @return array{kyc_approved: bool, pending_stores: int, banner: string|null}
     */
    private function gate(User $user, ?KycApplication $application): array
    {
        $pendingStores = Store::query()
            ->where('business_id', $user->business_id)
            ->whereIn('status', [Store::STATUS_PENDING, Store::STATUS_SUSPENDED])
            ->count();

        $approved = $application?->status === KycApplication::STATUS_APPROVED;
        $submitted = $application?->status === KycApplication::STATUS_SUBMITTED;

        $banner = null;

        if ($pendingStores > 0) {
            $banner = match (true) {
                $submitted => 'under_review',
                ! $approved => 'action_required',
                default => null,
            };
        }

        return [
            'kyc_approved' => $approved,
            'pending_stores' => $pendingStores,
            'banner' => $banner,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function applicationPayload(?KycApplication $application, bool $withSubmissionDetails = true): ?array
    {
        if (! $application) {
            return null;
        }

        $payload = [
            'id' => $application->id,
            'status' => $application->status,
            'status_label' => $application->status_metadata['label'] ?? ucfirst($application->status),
            // Both the parts and the joined form. The parts are what the wizard
            // edits; `legal_name` is what the review queue and every historical
            // record read, and a payload that omitted it would make a resubmit
            // look like it had lost the applicant's name.
            'legal_name' => $application->legal_name,
            'first_name' => $application->first_name,
            'middle_name' => $application->middle_name,
            'last_name' => $application->last_name,
            'phone_country_code' => $application->phone_country_code,
            'phone_number' => $application->phone_number,
            'phone_verified_at' => $application->phone_verified_at?->toISOString(),
            'date_of_birth' => $application->date_of_birth?->toDateString(),
            'address_line' => $application->address_line,
            'city' => $application->city,
            'state' => $application->state,
            'postal_code' => $application->postal_code,
            'country' => $application->country,
            'kyc_document_type_id' => $application->kyc_document_type_id,
            'document_type' => $application->documentType?->name,
            'kyc_document_id' => $application->kyc_document_id,
            'has_identification_document' => (bool) $application->identification_document_path,
            'identification_document_url' => $application->identification_document_path
                ? asset('storage/'.$application->identification_document_path)
                : null,
            'has_selfie' => (bool) $application->selfie_image_path,
            'selfie_url' => $application->selfie_image_path
                ? asset('storage/'.$application->selfie_image_path)
                : null,
            'submitted_at' => $application->submitted_at?->toISOString(),
            'approved_at' => $application->approved_at?->toISOString(),
            'rejected_at' => $application->rejected_at?->toISOString(),
            'review_notes' => $application->review_notes,
        ];

        if ($withSubmissionDetails) {
            $payload['device_type'] = $application->device_type;
            $payload['browser'] = $application->browser;
            $payload['ip_address'] = $application->ip_address;
        }

        return $payload;
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

    private function queueBusinessNotification(User $user, KycApplication $application): void
    {
        if (empty($user->email)) {
            return;
        }

        try {
            Mail::to($user->email)->queue(new BusinessKycSubmitted($user, $application));

            Log::info('business.kyc.business_mail_queued', [
                'user_id' => $user->id,
                'application_id' => $application->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('business.kyc.business_mail_queue_failed', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function queueAdminNotification(KycApplication $application): void
    {
        $admins = User::query()
            ->where('role', User::ROLE_SUPERADMIN)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($admins) && config('mail.from.address')) {
            $admins = [config('mail.from.address')];
        }

        if (empty($admins)) {
            Log::warning('business.kyc.admin_mail_skipped', [
                'application_id' => $application->id,
                'reason' => 'no_admin_recipients',
            ]);

            return;
        }

        try {
            Mail::to($admins)->queue(new AdminKycSubmitted($application));

            Log::info('business.kyc.admin_mail_queued', [
                'application_id' => $application->id,
                'admin_count' => count($admins),
            ]);
        } catch (\Throwable $e) {
            Log::error('business.kyc.admin_mail_queue_failed', [
                'application_id' => $application->id,
                'admin_count' => count($admins),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
