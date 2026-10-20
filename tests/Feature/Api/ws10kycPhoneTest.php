<?php

use App\Models\KycApplication;
use App\Models\KycDocumentType;
use App\Models\KycPhoneVerification;
use App\Models\Otp;
use App\Models\User;
use App\Services\Management\KycPhoneVerificationService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| WS-10 — KYC phone verification by SMS
|--------------------------------------------------------------------------
| The KYC form's phone is proved with a Termii-delivered code before the form
| may be submitted. These tests own the whole path: the outbound Termii call,
| normalisation, the OTP row, the refusal on submit, and the limits that guard
| a 6-digit secret.
|
| Helpers are duplicated here rather than imported from ws10kycTest.php: Pest
| loads the files into one process, so two definitions of the same helper name
| would be a redeclaration, and running a single file would miss the other.
*/

function ws10PhoneToken(User $user): string
{
    return $user->createToken('management-access', ['management'], now()->addHour())->plainTextToken;
}

function ws10PhoneDocumentType(): KycDocumentType
{
    return KycDocumentType::create([
        'name' => 'National Identification Number',
        'code' => 'nin',
        'description' => 'Your NIN slip',
        'is_active' => true,
    ]);
}

function ws10PhoneSubmission(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ada',
        'middle_name' => 'Ngozi',
        'last_name' => 'Obi',
        'phone_number' => '08031234567',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'address_line' => '1 Test Street',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'kyc_document_id' => 'NIN-123456789',
    ], $overrides);
}

function ws10PhoneOtp(string $phone = '+2348031234567'): Otp
{
    return Otp::query()
        ->where('identifier', $phone)
        ->where('type', KycPhoneVerificationService::OTP_TYPE)
        ->latest('id')
        ->firstOrFail();
}

beforeEach(function () {
    config([
        'services.termii.api_key' => 'test-termii-key',
        'services.termii.base_url' => 'https://api.ng.termii.com',
        'services.termii.sender_id' => 'OE Alert',
        'services.termii.channel' => 'dnd',
        'services.termii.default_country_code' => '234',
        'services.termii.timeout_ms' => 10000,
    ]);

    // `Http::fake()` alone does not reset a faked factory swapped in by an
    // earlier test in the same process.
    Http::swap(new Factory);
    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message_id' => 'termii-message-1'], 200),
    ]);
});

test('sending a code normalises a local number and hands Termii the digits form', function () {
    [$owner] = createBusinessOwner(['kyc' => 'none']);

    $this->withToken(ws10PhoneToken($owner))
        ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '0803 123 4567'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+2348031234567')
        ->assertJsonPath('data.expires_in', 600)
        ->assertJsonPath('message', 'Verification code sent by SMS.');

    $otp = ws10PhoneOtp();

    Http::assertSent(function ($request) use ($otp) {
        return $request->url() === 'https://api.ng.termii.com/api/sms/send'
            && $request['to'] === '2348031234567'
            && $request['from'] === 'OE Alert'
            && $request['type'] === 'plain'
            && $request['channel'] === 'dnd'
            && $request['api_key'] === 'test-termii-key'
            && $request['sms'] === "Your Storify verification code is {$otp->code}. This code expires in 10 minutes. Do not share with anyone.";
    });

    expect($otp->business_id)->toBeNull()
        ->and($otp->is_verified)->toBeFalse()
        ->and($otp->expires_at->greaterThan(now()->addMinutes(8)))->toBeTrue();
});

test('a wrong code is refused and the right one verifies the number', function () {
    [$owner] = createBusinessOwner(['kyc' => 'none']);
    $token = ws10PhoneToken($owner);

    $this->withToken($token)
        ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '08031234567'])
        ->assertOk();

    $otp = ws10PhoneOtp();

    // A code that is well-formed but not the one sent is the brute-force case
    // the throttle exists for; the service still refuses it on its own.
    $wrong = $otp->code === '000000' ? '111111' : '000000';

    $this->withToken($token)
        ->postJson('/api/v1/management/kyc/phone/verify', [
            'phone_number' => '08031234567',
            'code' => $wrong,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect(KycPhoneVerification::query()->count())->toBe(0);

    $this->withToken($token)
        ->postJson('/api/v1/management/kyc/phone/verify', [
            'phone_number' => '08031234567',
            'code' => $otp->code,
        ])
        ->assertOk()
        ->assertJsonPath('data.phone_verification.phone', '+2348031234567')
        ->assertJsonPath('data.phone_verification.verified', true)
        ->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.block_reason', null)
        ->assertJsonPath('message', 'Phone number verified.');

    $verification = KycPhoneVerification::query()->firstOrFail();

    expect($verification->user_id)->toBe($owner->id)
        ->and($verification->business_id)->toBe($owner->business_id)
        ->and($verification->phone)->toBe('+2348031234567')
        ->and($verification->verified_at)->not->toBeNull();

    // The step reads this payload, so a verified number has to clear the
    // blocker there too — not only in the verify response.
    $this->withToken($token)
        ->getJson('/api/v1/management/kyc')
        ->assertOk()
        ->assertJsonPath('data.block_reason', null)
        ->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.phone_verification.verified', true);
});

test('a phone number verified in one business does not unblock another business', function () {
    [$first] = createBusinessOwner(['kyc' => 'none']);
    [$second] = createBusinessOwner(['kyc' => 'none']);
    $type = ws10PhoneDocumentType();

    verifyKycPhone($first, '08031234567');

    $this->withToken(ws10PhoneToken($second))
        ->postJson('/api/v1/management/kyc', array_merge(ws10PhoneSubmission(), [
            'kyc_document_type_id' => $type->id,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone_number');

    expect(KycPhoneVerification::query()
        ->where('business_id', $second->business_id)
        ->count())->toBe(0);
});

test('submitting KYC requires the form phone to be the verified one', function () {
    [$owner] = createBusinessOwner(['kyc' => 'none']);
    $type = ws10PhoneDocumentType();
    $token = ws10PhoneToken($owner);
    $payload = ws10PhoneSubmission(['kyc_document_type_id' => $type->id]);

    // Nothing verified yet.
    $this->withToken($token)
        ->postJson('/api/v1/management/kyc', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone_number');

    expect(KycApplication::query()->count())->toBe(0);

    // A different number proved than the one on the form.
    verifyKycPhone($owner, '08039999999');

    $this->withToken($token)
        ->postJson('/api/v1/management/kyc', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone_number');

    expect(KycApplication::query()->count())->toBe(0);

    // The same number, typed differently: normalisation is what makes this
    // pass, and it is why comparison never uses the raw string.
    verifyKycPhone($owner, '+2348031234567');

    $this->withToken($token)
        ->postJson('/api/v1/management/kyc', $payload)
        ->assertCreated();

    $application = KycApplication::query()->where('user_id', $owner->id)->firstOrFail();

    expect($application->phone_number)->toBe('08031234567')
        ->and($application->phone_verified_at)->not->toBeNull();
});

test('a Termii failure refuses the send and leaves no usable code behind', function () {
    // A fresh factory: stubs registered in beforeEach would otherwise take
    // precedence, since Laravel matches them in registration order.
    Http::swap(new Factory);
    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message' => 'Invalid sender id'], 400),
    ]);

    [$owner] = createBusinessOwner(['kyc' => 'none']);

    $this->withToken(ws10PhoneToken($owner))
        ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '08031234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone_number');

    // A code that never reached anybody must not stay valid for ten minutes.
    expect(Otp::query()
        ->where('type', KycPhoneVerificationService::OTP_TYPE)
        ->count())->toBe(0);
});

test('the code is never written to the logs', function () {
    Log::spy();

    [$owner] = createBusinessOwner(['kyc' => 'none']);

    $this->withToken(ws10PhoneToken($owner))
        ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '08031234567'])
        ->assertOk();

    $code = ws10PhoneOtp()->code;

    foreach (['debug', 'info', 'notice', 'warning', 'error'] as $level) {
        Log::shouldNotHaveReceived($level, function ($message, $context = []) use ($code) {
            return str_contains(json_encode([$message, $context]), $code);
        });
    }
});

test('non-owners and unauthenticated callers cannot verify a phone', function () {
    [$owner, $business] = createBusinessOwner(['kyc' => 'none']);

    $staff = User::factory()->create([
        'role' => 'staff',
        'status' => 'active',
        'business_id' => $business->id,
    ]);
    setPermissionsTeamId($business->id);
    $staff->givePermissionTo('settings view');

    $this->withToken(ws10PhoneToken($staff))
        ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '08031234567'])
        ->assertStatus(403);

    $this->withToken(ws10PhoneToken($staff))
        ->postJson('/api/v1/management/kyc/phone/verify', [
            'phone_number' => '08031234567',
            'code' => '123456',
        ])
        ->assertStatus(403);

    // This stack answers an absent token with 403 rather than 401; the point
    // here is only that the request never reaches the controller.
    $this->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '08031234567'])
        ->assertStatus(403);

    expect(Otp::query()->count())->toBe(0)
        ->and(KycPhoneVerification::query()->count())->toBe(0)
        ->and($owner->id)->toBe($business->user_id);
});

test('the fourth code request in a minute is throttled', function () {
    [$owner] = createBusinessOwner(['kyc' => 'none']);
    $token = ws10PhoneToken($owner);

    foreach (range(1, 3) as $attempt) {
        $this->withToken($token)
            ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '08031234567'])
            ->assertOk();
    }

    $this->withToken($token)
        ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '08031234567'])
        ->assertStatus(429);
});

test('an invalid phone number is refused before any SMS is attempted', function () {
    [$owner] = createBusinessOwner(['kyc' => 'none']);

    $this->withToken(ws10PhoneToken($owner))
        ->postJson('/api/v1/management/kyc/phone/otp', ['phone_number' => '123'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone_number');

    Http::assertNothingSent();
});
