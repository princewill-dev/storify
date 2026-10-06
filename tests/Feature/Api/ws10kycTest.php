<?php

use App\Mail\AdminKycSubmitted;
use App\Mail\BusinessKycSubmitted;
use App\Models\KycApplication;
use App\Models\KycDocumentType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function ws10Token(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws10DocumentType(array $attributes = []): KycDocumentType
{
    return KycDocumentType::create(array_merge([
        'name' => 'National Identification Number',
        'code' => 'nin',
        'description' => 'Your NIN slip',
        'is_active' => true,
    ], $attributes));
}

function ws10Application(User $user, array $attributes = []): KycApplication
{
    // forceFill: business_id is not in the model's $fillable (see the model),
    // so create() would drop it and the tenant scoping would miss the row.
    $application = new KycApplication;

    $application->forceFill(array_merge([
        'user_id' => $user->id,
        'business_id' => $user->business_id,
        'status' => KycApplication::STATUS_SUBMITTED,
        'legal_name' => 'Ada Obi',
        'phone_number' => '08030000000',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'address_line' => '1 Test Street',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'submitted_at' => now()->subDay(),
    ], $attributes))->save();

    return $application;
}

function ws10Submission(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'Ada Obi',
        'phone_number' => '08030000000',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'address_line' => '1 Test Street',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'kyc_document_id' => 'NIN-123456789',
    ], $overrides);
}

function ws10Post(object $test, User $user, array $payload, array $files = [], array $headers = [])
{
    return $test->withToken(ws10Token($user))
        ->withHeaders(array_merge(['Accept' => 'application/json'], $headers))
        ->post('/api/v1/management/kyc', array_merge($payload, $files));
}

test('a fresh owner is told KYC is required and sees the active document types', function () {
    [$owner] = createBusinessOwner();
    ws10DocumentType(['name' => 'Passport', 'code' => 'passport']);
    ws10DocumentType(['name' => 'Retired slip', 'code' => 'old', 'is_active' => false]);

    $this->withToken(ws10Token($owner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.application', null)
        ->assertJsonPath('data.state', 'required')
        ->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.block_reason', null)
        ->assertJsonPath('data.gate.kyc_approved', false)
        ->assertJsonCount(1, 'data.document_types')
        ->assertJsonPath('data.document_types.0.code', 'passport');
});

test('submitting KYC stores the application, captures device metadata and sets the owner pending', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner();
    $type = ws10DocumentType();

    $response = ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]), [
        'identification_document' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        'selfie_image' => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
    ], [
        'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.state', 'submitted')
        ->assertJsonPath('data.can_submit', false)
        ->assertJsonPath('data.block_reason', 'under_review')
        ->assertJsonPath('data.application.status', 'submitted')
        ->assertJsonPath('data.application.legal_name', 'Ada Obi')
        ->assertJsonPath('data.application.document_type', $type->name)
        ->assertJsonPath('data.application.has_identification_document', true)
        ->assertJsonPath('data.application.has_selfie', true)
        ->assertJsonPath('data.application.device_type', 'mobile');

    expect($response->json('data.application.browser'))->toContain('iPhone');

    $application = KycApplication::query()->where('user_id', $owner->id)->firstOrFail();

    expect($application->business_id)->toBe($owner->business_id)
        ->and($application->status)->toBe(KycApplication::STATUS_SUBMITTED)
        ->and($application->submitted_at)->not->toBeNull()
        ->and($application->approved_at)->toBeNull()
        ->and($application->rejected_at)->toBeNull()
        ->and($application->review_notes)->toBeNull()
        ->and($application->ip_address)->not->toBeNull()
        ->and($application->browser)->not->toBeNull();

    Storage::disk('public')->assertExists($application->identification_document_path);
    Storage::disk('public')->assertExists($application->selfie_image_path);

    expect($application->identification_document_path)->toStartWith('kyc/documents/')
        ->and($application->selfie_image_path)->toStartWith('kyc/selfies/')
        ->and($owner->fresh()->status)->toBe('pending');
});

test('submitting KYC queues the business and admin notification emails', function () {
    Mail::fake();
    Storage::fake('public');

    [$owner] = createBusinessOwner();
    $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'business_id' => null]);
    $type = ws10DocumentType();

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))->assertCreated();

    Mail::assertQueued(BusinessKycSubmitted::class, fn ($mail) => $mail->hasTo($owner->email));
    Mail::assertQueued(AdminKycSubmitted::class, fn ($mail) => $mail->hasTo($superadmin->email));
});

test('the admin notification falls back to the configured from address', function () {
    Mail::fake();

    [$owner] = createBusinessOwner();
    config(['mail.from.address' => 'ops@storify.test']);
    $type = ws10DocumentType();

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))->assertCreated();

    Mail::assertQueued(AdminKycSubmitted::class, fn ($mail) => $mail->hasTo('ops@storify.test'));
});

test('a mail failure never blocks the KYC submission', function () {
    Log::spy();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    [$owner] = createBusinessOwner();
    config(['mail.from.address' => 'ops@storify.test']);
    $type = ws10DocumentType();

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))->assertCreated();

    expect(KycApplication::query()->where('user_id', $owner->id)->count())->toBe(1);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context = []) => $message === 'business.kyc.business_mail_queue_failed');

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context = []) => $message === 'business.kyc.admin_mail_queue_failed');
});

test('KYC validation mirrors the legacy rules including the 18 year minimum', function () {
    [$owner] = createBusinessOwner();

    $response = ws10Post($this, $owner, [], []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors([
            'legal_name', 'phone_number', 'date_of_birth', 'address_line',
            'city', 'state', 'country', 'kyc_document_type_id', 'kyc_document_id',
        ]);

    $type = ws10DocumentType();

    $response = ws10Post($this, $owner, ws10Submission([
        'date_of_birth' => now()->subYears(17)->toDateString(),
        'kyc_document_type_id' => $type->id,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors('date_of_birth')
        ->assertJsonPath('errors.date_of_birth.0', 'You must be at least 18 years old to onboard as a business owner.');

    expect(KycApplication::query()->count())->toBe(0);
});

test('document uploads enforce the legacy file type and size limits', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner();
    $type = ws10DocumentType();

    $response = ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]), [
        'identification_document' => UploadedFile::fake()->create('installer.exe', 100, 'application/x-msdownload'),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('identification_document')
        ->assertJsonPath('errors.identification_document.0', 'Identification must be a JPG, PNG, or PDF file.');

    $response = ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]), [
        'identification_document' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('identification_document')
        ->assertJsonPath('errors.identification_document.0', 'Identification file size cannot exceed 5MB.');

    // Both uploads are optional in legacy: a text-only application is valid.
    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))->assertCreated();
});

test('an inactive document type is refused', function () {
    [$owner] = createBusinessOwner();
    $type = ws10DocumentType(['name' => 'Retired', 'code' => 'retired', 'is_active' => false]);

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('kyc_document_type_id');

    expect(KycApplication::query()->count())->toBe(0);
});

test('an application under review blocks a second submission', function () {
    [$owner] = createBusinessOwner();
    $type = ws10DocumentType();
    $existing = ws10Application($owner, ['status' => KycApplication::STATUS_SUBMITTED]);

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your KYC is currently under review.');

    expect(KycApplication::query()->count())->toBe(1)
        ->and(KycApplication::query()->firstOrFail()->id)->toBe($existing->id);
});

test('an approved application blocks resubmission', function () {
    [$owner] = createBusinessOwner();
    $type = ws10DocumentType();
    ws10Application($owner, [
        'status' => KycApplication::STATUS_APPROVED,
        'approved_at' => now()->subDay(),
    ]);

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your KYC has already been approved.');

    $this->withToken(ws10Token($owner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.state', 'approved')
        ->assertJsonPath('data.can_submit', false)
        ->assertJsonPath('data.block_reason', 'approved');

    expect(KycApplication::query()->count())->toBe(1);
});

test('a rejected owner may resubmit and every application is retained', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner();
    $type = ws10DocumentType();
    $rejected = ws10Application($owner, [
        'status' => KycApplication::STATUS_REJECTED,
        'rejected_at' => now()->subDay(),
        'review_notes' => 'Document was blurry.',
    ]);

    ws10Post($this, $owner, ws10Submission(['legal_name' => 'Ada Obi-Chukwu', 'kyc_document_type_id' => $type->id]))
        ->assertCreated()
        ->assertJsonPath('data.application.legal_name', 'Ada Obi-Chukwu');

    $applications = KycApplication::query()->where('user_id', $owner->id)->orderBy('id')->get();

    expect($applications)->toHaveCount(2)
        ->and($applications[0]->id)->toBe($rejected->id)
        ->and($applications[0]->status)->toBe(KycApplication::STATUS_REJECTED)
        ->and($applications[0]->review_notes)->toBe('Document was blurry.')
        ->and($applications[1]->status)->toBe(KycApplication::STATUS_SUBMITTED);

    $this->withToken(ws10Token($owner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.application.legal_name', 'Ada Obi-Chukwu')
        ->assertJsonCount(2, 'data.history')
        ->assertJsonPath('data.history.1.status', 'rejected')
        ->assertJsonPath('data.history.1.review_notes', 'Document was blurry.');
});

test('a resubmission replaces the superseded document instead of orphaning it', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner();
    $type = ws10DocumentType();

    Storage::disk('public')->put('kyc/documents/old-id.pdf', 'old');
    Storage::disk('public')->put('kyc/selfies/old-selfie.jpg', 'old');

    $rejected = ws10Application($owner, [
        'status' => KycApplication::STATUS_REJECTED,
        'rejected_at' => now()->subDay(),
        'identification_document_path' => 'kyc/documents/old-id.pdf',
        'selfie_image_path' => 'kyc/selfies/old-selfie.jpg',
    ]);

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]), [
        'identification_document' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
    ])->assertCreated();

    // The replaced document is gone from disk and the superseded row no longer
    // points at it; the selfie was not replaced, so it survives untouched.
    Storage::disk('public')->assertMissing('kyc/documents/old-id.pdf');
    Storage::disk('public')->assertExists('kyc/selfies/old-selfie.jpg');

    $previous = $rejected->fresh();

    expect($previous->identification_document_path)->toBeNull()
        ->and($previous->selfie_image_path)->toBe('kyc/selfies/old-selfie.jpg');

    $latest = KycApplication::query()->where('user_id', $owner->id)->orderByDesc('id')->firstOrFail();

    Storage::disk('public')->assertExists($latest->identification_document_path);
});

test('a resubmission without new uploads keeps the previous documents', function () {
    Storage::fake('public');

    [$owner] = createBusinessOwner();
    $type = ws10DocumentType();

    Storage::disk('public')->put('kyc/documents/old-id.pdf', 'old');

    $rejected = ws10Application($owner, [
        'status' => KycApplication::STATUS_REJECTED,
        'rejected_at' => now()->subDay(),
        'identification_document_path' => 'kyc/documents/old-id.pdf',
    ]);

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))->assertCreated();

    Storage::disk('public')->assertExists('kyc/documents/old-id.pdf');

    expect($rejected->fresh()->identification_document_path)->toBe('kyc/documents/old-id.pdf');
});

test('an unverified owner cannot submit and is told to verify their email', function () {
    [$owner] = createBusinessOwner(['is_verified' => false]);
    $type = ws10DocumentType();

    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))
        ->assertStatus(403)
        ->assertJsonPath('message', 'Verify your email before submitting your KYC information.');

    $this->withToken(ws10Token($owner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.state', 'required')
        ->assertJsonPath('data.can_submit', false)
        ->assertJsonPath('data.block_reason', 'email_unverified');

    expect(KycApplication::query()->count())->toBe(0);
});

test('KYC is refused to anyone who is not the business owner', function () {
    [$owner, $business] = createBusinessOwner();
    $type = ws10DocumentType();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    // Give the staff member the route permission so the refusal can only come
    // from the owner gate, not the permission middleware.
    setPermissionsTeamId($business->id);
    $staff->givePermissionTo('settings view');

    $this->withToken(ws10Token($staff))
        ->getJson('/api/v1/management/kyc')
        ->assertStatus(403)
        ->assertJsonPath('message', 'KYC verification can only be managed by the business owner.');

    ws10Post($this, $staff, ws10Submission(['kyc_document_type_id' => $type->id]))
        ->assertStatus(403);

    expect(KycApplication::query()->count())->toBe(0);
});

test('a staff member without the settings permission is refused by the route gate', function () {
    [$owner, $business] = createBusinessOwner();

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);

    $this->withToken(ws10Token($staff))
        ->getJson('/api/v1/management/kyc')
        ->assertStatus(403);
});

test('another business never sees this business KYC application', function () {
    [$owner] = createBusinessOwner();
    ws10Application($owner, [
        'status' => KycApplication::STATUS_REJECTED,
        'legal_name' => 'Secret Applicant',
        'review_notes' => 'Private note',
    ]);

    [$otherOwner] = createBusinessOwner();

    $this->withToken(ws10Token($otherOwner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.application', null)
        ->assertJsonPath('data.history', [])
        ->assertJsonPath('data.state', 'required');

    $type = ws10DocumentType();

    ws10Post($this, $otherOwner, ws10Submission(['kyc_document_type_id' => $type->id]))->assertCreated();

    $latest = KycApplication::query()->orderByDesc('id')->firstOrFail();

    expect($latest->user_id)->toBe($otherOwner->id)
        ->and($latest->business_id)->toBe($otherOwner->business_id)
        ->and(KycApplication::query()->where('business_id', $owner->business_id)->count())->toBe(1);
});

test('the gate payload drives the dashboard banners for pending stores', function () {
    [$owner, $business] = createBusinessOwner();

    Store::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'status' => 'pending']);
    Store::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'status' => 'suspended']);
    Store::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'status' => 'active']);

    $this->withToken(ws10Token($owner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.gate.pending_stores', 2)
        ->assertJsonPath('data.gate.banner', 'action_required');

    $type = ws10DocumentType();
    ws10Post($this, $owner, ws10Submission(['kyc_document_type_id' => $type->id]))->assertCreated();

    $this->withToken(ws10Token($owner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.gate.banner', 'under_review');

    KycApplication::query()->firstOrFail()->forceFill([
        'status' => KycApplication::STATUS_APPROVED,
        'approved_at' => now(),
    ])->save();

    $this->withToken(ws10Token($owner))
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.gate.kyc_approved', true)
        ->assertJsonPath('data.gate.banner', null);
});

test('store activation is refused until KYC is approved', function () {
    // A live trial, so the subscription gate passes and this test exercises
    // the KYC refusal rather than the plan refusal in front of it.
    [$owner, $business] = createBusinessOwner(['trial_ends_at' => now()->addWeek()]);

    $store = Store::factory()->create([
        'user_id' => $owner->id,
        'business_id' => $business->id,
        'status' => 'pending',
    ]);

    $this->withToken(ws10Token($owner))
        ->patchJson("/api/v1/management/stores/{$store->store_id}/activate")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Complete KYC verification before activating this store.');

    expect($store->fresh()->status)->toBe('pending');

    ws10Application($owner, [
        'status' => KycApplication::STATUS_APPROVED,
        'approved_at' => now(),
    ]);

    $this->withToken(ws10Token($owner))
        ->patchJson("/api/v1/management/stores/{$store->store_id}/activate")
        ->assertOk();

    expect($store->fresh()->status)->toBe('active');
});

test('the KYC endpoints require authentication', function () {
    $this->getJson('/api/v1/management/kyc')->assertStatus(401);
    $this->postJson('/api/v1/management/kyc', [])->assertStatus(401);
});
