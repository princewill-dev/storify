<?php

use App\Mail\KycApproved;
use App\Mail\KycRejected;
use App\Models\ActivityLog;
use App\Models\KycApplication;
use App\Models\KycDocumentType;
use App\Models\User;
use App\Services\KycApprovalService;
use Database\Seeders\SpatiePermissionSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * WS-3 — KYC review (admin console).
 *
 * Covers the queue (default `submitted`, status counts, filters, pagination),
 * the review payload including the fields legacy stored but never showed, the
 * approve/reject transitions with their mails and audit rows, the status
 * guard that legacy lacked, the honest rejection messaging when mail queueing
 * fails, the KycApprovalService auto-approval contract WS-4/WS-8 consume, the
 * cross-tenant and permission boundaries, and the route-access audit trail.
 */
function ad03Token(User $admin): string
{
    return $admin->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
}

function ad03SuperAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    return User::factory()->create([
        'role' => User::ROLE_SUPERADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);
}

function ad03PlatformAdmin(): User
{
    (new SpatiePermissionSeeder)->run();

    $admin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    setPermissionsTeamId(null);
    $admin->assignRole('Platform Admin');

    return $admin;
}

function ad03DocumentType(array $attributes = []): KycDocumentType
{
    return KycDocumentType::create(array_merge([
        'name' => 'National Identification Number',
        'code' => 'nin',
        'description' => 'Your NIN slip',
        'is_active' => true,
    ], $attributes));
}

/**
 * Build an application the way the management submission path does.
 *
 * forceFill: business_id, kyc_document_id, device_type, browser and
 * ip_address are columns the model's $fillable predates, so fill() would
 * silently drop them (the same trap legacy fell into).
 */
function ad03Application(User $owner, array $attributes = []): KycApplication
{
    $application = new KycApplication;

    $application->forceFill(array_merge([
        'user_id' => $owner->id,
        'business_id' => $owner->business_id,
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

/**
 * Drop guard state carried over from an earlier request in this test.
 *
 * Sanctum's RequestGuard caches the user it resolved, and the test client
 * reuses one application — and therefore one guard instance — across every
 * request in a test. Without this, a later request made with a different
 * token (or with none at all) is still judged as the first identity that
 * authenticated. Each real request gets a fresh process, so resetting here
 * makes the simulation faithful rather than changing what is asserted.
 */
function ad03FreshAuth(): void
{
    auth()->forgetGuards();
}

function ad03Get(object $test, User $admin, string $path)
{
    ad03FreshAuth();

    return $test->getJson('/api/v1/admin/'.$path, ['Authorization' => 'Bearer '.ad03Token($admin)]);
}

function ad03Post(object $test, User $admin, string $path, array $payload = [])
{
    ad03FreshAuth();

    return $test->postJson('/api/v1/admin/'.$path, $payload, ['Authorization' => 'Bearer '.ad03Token($admin)]);
}

test('the kyc queue opens on submitted applications and carries status counts', function () {
    $admin = ad03SuperAdmin();
    [$ownerA, $businessA] = createBusinessOwner();
    [$ownerB, $businessB] = createBusinessOwner();

    $old = ad03Application($ownerA, ['submitted_at' => now()->subDays(2)]);
    $new = ad03Application($ownerB, ['submitted_at' => now()->subHour(), 'legal_name' => 'Newest Applicant']);
    ad03Application($ownerA, ['status' => 'approved', 'approved_at' => now()->subDay()]);
    ad03Application($ownerB, ['status' => 'rejected', 'rejected_at' => now()->subDay()]);
    ad03Application($ownerA, ['status' => 'draft']);

    $response = ad03Get($this, $admin, 'kyc-applications');

    $response->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.status_counts.submitted', 2)
        ->assertJsonPath('meta.status_counts.approved', 1)
        ->assertJsonPath('meta.status_counts.rejected', 1)
        ->assertJsonPath('meta.status_counts.draft', 1)
        ->assertJsonPath('meta.status_counts.all', 5)
        // Latest submitted first, like legacy's latest('submitted_at').
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.0.legal_name', 'Newest Applicant')
        ->assertJsonPath('data.0.status_label', 'Submitted');

    // The queue is platform-wide: applications from both businesses appear.
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$new->id, $old->id]);

    $payload = $response->json('data.0');
    expect($payload['business']['business_code'])->toBe($businessB->business_code)
        ->and($payload['business']['name'])->toBe($businessB->name)
        ->and($payload['owner']['email'])->toBe($ownerB->email)
        ->and($payload['owner']['account_code'])->toBe($ownerB->account_code)
        ->and($payload['has_identification_document'])->toBeFalse()
        ->and($payload['has_selfie'])->toBeFalse();

    // The dashboard tile and the queue's default filter must agree.
    ad03Get($this, $admin, 'dashboard')->assertJsonPath('data.stats.kyc_pending', 2);

    // Opening the queue is itself audited (route-level AdminApiActivityLogger).
    $access = ActivityLog::where('action', 'admin_route_accessed')->get()
        ->first(fn (ActivityLog $row) => ($row->metadata['route'] ?? null) === 'api.admin.kyc-applications.index');

    expect($access)->not->toBeNull();
});

test('the kyc queue filters by status, searches applicants and paginates', function () {
    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner(['name' => 'Searchable Owner']);

    $submitted = ad03Application($owner);
    ad03Application($owner, ['status' => 'approved', 'approved_at' => now()]);
    ad03Application($owner, ['status' => 'rejected', 'rejected_at' => now()]);

    // Explicit status.
    ad03Get($this, $admin, 'kyc-applications?status=approved')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.status', 'approved');

    // `all` opts out of the submitted default.
    ad03Get($this, $admin, 'kyc-applications?status=all')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);

    // Search by legal name, owner email and business name.
    ad03Get($this, $admin, 'kyc-applications?status=all&q=Ada')->assertJsonPath('meta.total', 3);
    ad03Get($this, $admin, 'kyc-applications?q='.urlencode($owner->email))
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $submitted->id);
    ad03Get($this, $admin, 'kyc-applications?status=all&q='.urlencode($owner->business->name))
        ->assertJsonPath('meta.total', 3);

    // Search plus status combine, and pagination reports the unfiltered total.
    ad03Get($this, $admin, 'kyc-applications?status=all&per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(1, 'data');
});

test('the kyc queue filters are validated', function () {
    $admin = ad03SuperAdmin();

    ad03Get($this, $admin, 'kyc-applications?status=bogus')->assertStatus(422)->assertJsonValidationErrors('status');
    ad03Get($this, $admin, 'kyc-applications?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
    ad03Get($this, $admin, 'kyc-applications?q='.str_repeat('a', 300))->assertStatus(422)->assertJsonValidationErrors('q');
});

test('the review screen surfaces the fields legacy stored but never showed', function () {
    $admin = ad03SuperAdmin();
    [$owner, $business] = createBusinessOwner();
    $type = ad03DocumentType();

    $older = ad03Application($owner, [
        'status' => 'rejected',
        'rejected_at' => now()->subDays(3),
        'review_notes' => 'The document photo was unreadable',
    ]);

    $application = ad03Application($owner, [
        'kyc_document_type_id' => $type->id,
        'kyc_document_id' => 'NIN-123456789',
        'identification_document_path' => 'kyc/documents/id.pdf',
        'selfie_image_path' => 'kyc/selfies/selfie.jpg',
        'device_type' => 'mobile',
        'browser' => 'Safari/17',
        'ip_address' => '10.9.9.9',
        'payload' => ['next_of_kin' => 'Ada Obi'],
    ]);

    ad03Get($this, $admin, 'kyc-applications/'.$application->id)
        ->assertOk()
        ->assertJsonPath('data.application.id', $application->id)
        ->assertJsonPath('data.application.status_label', 'Submitted')
        ->assertJsonPath('data.application.document_type.name', $type->name)
        ->assertJsonPath('data.application.kyc_document_id', 'NIN-123456789')
        ->assertJsonPath('data.application.identification_document_url', asset('storage/kyc/documents/id.pdf'))
        ->assertJsonPath('data.application.selfie_url', asset('storage/kyc/selfies/selfie.jpg'))
        ->assertJsonPath('data.application.device_type', 'mobile')
        ->assertJsonPath('data.application.browser', 'Safari/17')
        ->assertJsonPath('data.application.ip_address', '10.9.9.9')
        ->assertJsonPath('data.application.payload.next_of_kin', 'Ada Obi')
        ->assertJsonPath('data.application.can_review', true)
        ->assertJsonPath('data.application.business.business_code', $business->business_code)
        ->assertJsonPath('data.application.owner.email', $owner->email)
        // The earlier rejection travels with the review screen so a
        // resubmission can be judged against what went wrong before.
        ->assertJsonPath('data.application.history.0.id', $older->id)
        ->assertJsonPath('data.application.history.0.status', 'rejected')
        ->assertJsonPath('data.application.history.0.review_notes', 'The document photo was unreadable');
});

test('approving activates the owner, records the reviewer and queues the approval mail', function () {
    Mail::fake();

    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();
    $owner->forceFill(['status' => 'pending'])->save();

    $application = ad03Application($owner);

    $response = ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/approve', [
        'review_notes' => 'Documents check out',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.application.status', 'approved')
        ->assertJsonPath('data.application.review_notes', 'Documents check out')
        ->assertJsonPath('data.application.reviewer.id', $admin->id)
        ->assertJsonPath('data.application.can_review', false)
        ->assertJsonPath('data.notified', true);

    expect($response->json('message'))->toContain('approved');

    $application->refresh();

    expect($application->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($application->approved_at)->not->toBeNull()
        ->and($application->rejected_at)->toBeNull()
        ->and($application->reviewed_by)->toBe($admin->id)
        ->and($application->review_notes)->toBe('Documents check out')
        ->and($owner->fresh()->status)->toBe('active');

    Mail::assertQueued(KycApproved::class, fn (KycApproved $mail) => $mail->hasTo($owner->email)
        && $mail->application->id === $application->id);

    $log = ActivityLog::where('action', 'admin.business_kyc.approved')->first();

    expect($log)->not->toBeNull()
        ->and($log->subject_type)->toBe(KycApplication::class)
        ->and($log->subject_id)->toBe($application->id)
        ->and($log->business_id)->toBe($owner->business_id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->old_values['status'])->toBe('submitted')
        ->and($log->new_values['status'])->toBe('approved')
        ->and($log->metadata['notified'])->toBe(true);
});

test('an application can only be actioned while it is submitted', function () {
    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();

    $application = ad03Application($owner);

    ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/approve')->assertOk();

    // Re-approving by direct POST used to work in legacy; it is refused now.
    ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/approve')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/reject', ['review_notes' => 'Too late'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($application->fresh()->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($application->fresh()->review_notes)->toBeNull();

    $second = ad03Application($owner);

    ad03Post($this, $admin, 'kyc-applications/'.$second->id.'/reject', ['review_notes' => 'Blurry'])
        ->assertOk();

    ad03Post($this, $admin, 'kyc-applications/'.$second->id.'/approve')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    ad03Post($this, $admin, 'kyc-applications/'.$second->id.'/reject', ['review_notes' => 'Again'])
        ->assertStatus(422);

    expect($second->fresh()->status)->toBe(KycApplication::STATUS_REJECTED)
        ->and($second->fresh()->review_notes)->toBe('Blurry');
});

test('approve and reject notes are validated like legacy', function () {
    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();
    $application = ad03Application($owner);

    ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/approve', [
        'review_notes' => str_repeat('x', 2001),
    ])->assertStatus(422)->assertJsonValidationErrors('review_notes');

    // Reject's reason is required; approve's is optional.
    ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/reject', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('review_notes');

    ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/reject', [
        'review_notes' => str_repeat('x', 2001),
    ])->assertStatus(422)->assertJsonValidationErrors('review_notes');

    expect($application->fresh()->status)->toBe(KycApplication::STATUS_SUBMITTED);
});

test('rejecting moves the owner back to pending and emails the reason', function () {
    Mail::fake();

    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();

    $application = ad03Application($owner);

    $response = ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/reject', [
        'review_notes' => 'The document photo is unreadable',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.application.status', 'rejected')
        ->assertJsonPath('data.application.review_notes', 'The document photo is unreadable')
        ->assertJsonPath('data.application.can_review', false)
        ->assertJsonPath('data.notified', true);

    // Unlike legacy's flash, this claim is backed by an actually queued mail.
    expect($response->json('message'))->toContain('notified');

    $application->refresh();

    expect($application->status)->toBe(KycApplication::STATUS_REJECTED)
        ->and($application->rejected_at)->not->toBeNull()
        ->and($application->approved_at)->toBeNull()
        ->and($application->reviewed_by)->toBe($admin->id)
        ->and($owner->fresh()->status)->toBe('pending');

    Mail::assertQueued(KycRejected::class, fn (KycRejected $mail) => $mail->hasTo($owner->email)
        && $mail->application->review_notes === 'The document photo is unreadable');

    $log = ActivityLog::where('action', 'admin.business_kyc.rejected')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['status'])->toBe('submitted')
        ->and($log->new_values['status'])->toBe('rejected')
        ->and($log->metadata['notified'])->toBe(true);
});

test('the reject response does not over-promise when the notification cannot be queued', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();
    $application = ad03Application($owner);

    $response = ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/reject', [
        'review_notes' => 'Address does not match',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.application.status', 'rejected')
        ->assertJsonPath('data.notified', false);

    expect($response->json('message'))->toContain('could not be queued');

    // The rejection itself must survive the mail failure.
    expect($application->fresh()->status)->toBe(KycApplication::STATUS_REJECTED)
        ->and($owner->fresh()->status)->toBe('pending');

    $log = ActivityLog::where('action', 'admin.business_kyc.rejected')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['notified'])->toBe(false);
});

test('a mail failure never blocks an approval', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();
    $application = ad03Application($owner);

    $response = ad03Post($this, $admin, 'kyc-applications/'.$application->id.'/approve');

    $response->assertOk()
        ->assertJsonPath('data.application.status', 'approved')
        ->assertJsonPath('data.notified', false);

    expect($response->json('message'))->toContain('could not be queued');

    expect($application->fresh()->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($owner->fresh()->status)->toBe('active');
});

test('auto-approval persists the reviewer note and leaves closed applications alone', function () {
    Mail::fake();

    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();
    $owner->forceFill(['status' => 'pending'])->save();

    $draft = ad03Application($owner, ['status' => 'draft']);
    $rejected = ad03Application($owner, ['status' => 'rejected', 'rejected_at' => now()->subDay(), 'review_notes' => 'Old reason']);

    $service = app(KycApprovalService::class);

    // Nothing is open: a draft was never submitted and a rejection is a
    // decision the owner has not corrected (legacy would have approved both).
    expect($service->autoApproveOpenApplication($owner))->toBeNull();
    expect($draft->fresh()->status)->toBe(KycApplication::STATUS_DRAFT)
        ->and($rejected->fresh()->status)->toBe(KycApplication::STATUS_REJECTED);

    $submitted = ad03Application($owner);

    $approved = $service->autoApproveOpenApplication(
        $owner,
        $admin,
        'Auto-approved during business activation: goodwill',
    );

    expect($approved->id)->toBe($submitted->id)
        ->and($approved->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->rejected_at)->toBeNull()
        ->and($approved->reviewed_by)->toBe($admin->id)
        ->and($approved->review_notes)->toBe('Auto-approved during business activation: goodwill')
        ->and($owner->fresh()->status)->toBe('active')
        ->and($rejected->fresh()->status)->toBe(KycApplication::STATUS_REJECTED);

    Mail::assertQueued(KycApproved::class, fn (KycApproved $mail) => $mail->hasTo($owner->email));

    $log = ActivityLog::where('action', 'admin.business_kyc.auto_approved')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['auto_approved'])->toBe(true)
        ->and($log->new_values['review_notes'])->toBe('Auto-approved during business activation: goodwill');
});

test('auto-approval targets the newest submitted application', function () {
    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();

    $older = ad03Application($owner, ['submitted_at' => now()->subDays(5)]);
    $newer = ad03Application($owner, ['submitted_at' => now()->subHour()]);

    $approved = app(KycApprovalService::class)->autoApproveOpenApplication($owner, $admin, 'Activation');

    expect($approved->id)->toBe($newer->id)
        ->and($newer->fresh()->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($older->fresh()->status)->toBe(KycApplication::STATUS_SUBMITTED);
});

test('a rejected owner can resubmit and be approved — the legacy loop closes', function () {
    Mail::fake();

    $admin = ad03SuperAdmin();
    [$owner] = createBusinessOwner();

    $first = ad03Application($owner);

    ad03Post($this, $admin, 'kyc-applications/'.$first->id.'/reject', [
        'review_notes' => 'Address does not match your documents',
    ])->assertOk();

    expect($owner->fresh()->status)->toBe('pending');

    // The management KYC screen resubmits as a brand-new row (WS-10) and puts
    // the owner back under review.
    $second = ad03Application($owner, ['legal_name' => 'Ada Obi (corrected)']);

    ad03Post($this, $admin, 'kyc-applications/'.$second->id.'/approve', [
        'review_notes' => 'Correction confirmed',
    ])->assertOk();

    expect($second->fresh()->status)->toBe(KycApplication::STATUS_APPROVED)
        ->and($first->fresh()->status)->toBe(KycApplication::STATUS_REJECTED)
        ->and($owner->fresh()->status)->toBe('active');

    // The review screen shows the earlier rejection as history.
    ad03Get($this, $admin, 'kyc-applications/'.$second->id)
        ->assertOk()
        ->assertJsonPath('data.application.history.0.id', $first->id)
        ->assertJsonPath('data.application.history.0.status', 'rejected');
});

test('a business-scoped account cannot reach the platform kyc queue', function () {
    [$owner, $business] = createBusinessOwner();
    $application = ad03Application($owner);

    // The in-business Super Admin role bundles the admin.* permissions, so
    // the middleware gate alone would let this account through.
    setPermissionsTeamId($business->id);

    expect($owner->can('admin.businesses'))->toBeTrue();

    $token = $owner->createToken('admin-access', ['admin'], now()->addHour())->plainTextToken;
    $headers = ['Authorization' => 'Bearer '.$token];

    $this->getJson('/api/v1/admin/kyc-applications', $headers)->assertStatus(403);
    $this->getJson('/api/v1/admin/kyc-applications/'.$application->id, $headers)->assertStatus(403);
    $this->postJson('/api/v1/admin/kyc-applications/'.$application->id.'/approve', [], $headers)->assertStatus(403);
    $this->postJson('/api/v1/admin/kyc-applications/'.$application->id.'/reject', ['review_notes' => 'No'], $headers)->assertStatus(403);

    expect($application->fresh()->status)->toBe(KycApplication::STATUS_SUBMITTED)
        ->and($owner->fresh()->status)->toBe('active');
});

test('the kyc routes require the admin.businesses permission', function () {
    $platformAdmin = ad03PlatformAdmin();

    ad03Get($this, $platformAdmin, 'kyc-applications')->assertOk();

    $plainAdmin = User::factory()->create([
        'role' => User::ROLE_ADMIN,
        'status' => 'active',
        'is_verified' => true,
        'business_id' => null,
    ]);

    ad03Get($this, $plainAdmin, 'kyc-applications')->assertStatus(403);
});

test('management tokens and guests are refused', function () {
    [$owner] = createBusinessOwner();

    $managementToken = $owner->createToken('management-access', ['management'], now()->addHour())->plainTextToken;

    $this->getJson('/api/v1/admin/kyc-applications', ['Authorization' => 'Bearer '.$managementToken])
        ->assertStatus(403);

    // A guest carries no token: forget the identity the request above
    // resolved, so these are genuinely unauthenticated (401) rather than
    // re-judged as the management-token owner (which would be a 403).
    ad03FreshAuth();
    $this->getJson('/api/v1/admin/kyc-applications')->assertStatus(401);

    ad03FreshAuth();
    $this->postJson('/api/v1/admin/kyc-applications/1/approve')->assertStatus(401);
});

test('an unknown application is a 404 for the admin', function () {
    $admin = ad03SuperAdmin();

    ad03Get($this, $admin, 'kyc-applications/999999')->assertNotFound();
    ad03Post($this, $admin, 'kyc-applications/999999/approve')->assertNotFound();
});
