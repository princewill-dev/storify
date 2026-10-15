<?php

use App\Enums\BusinessRegistrationType;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| WS-54 — is the business registered, and the certificate that says so
|--------------------------------------------------------------------------
| The middle step of the setup flow. Three properties matter here:
|
|  - `is_registered` has three states, and "not asked yet" is not "no";
|  - answering "no" clears the evidence rather than leaving it behind;
|  - the certificate is required when registering, and not re-required on every
|    later save of the number beside it.
*/

function ws54rToken(User $user, array $abilities = ['management']): string
{
    return $user->createToken('management-access', $abilities, now()->addHour())->plainTextToken;
}

function ws54rBusiness(array $businessAttributes = []): array
{
    return createBusinessOwner(['trial_ends_at' => now()->addWeek()], $businessAttributes);
}

/** A certificate-looking upload — a real PNG header so `mimes` accepts it. */
function ws54rCertificate(string $name = 'cac.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 200, 200);
}

test('a business that has never answered reads as unanswered, not as unregistered', function () {
    [$owner] = ws54rBusiness();

    $this->withToken(ws54rToken($owner))
        ->getJson('/api/v1/management/business/registration')
        ->assertOk()
        // null, not false. Every business that predates this column is in this
        // state, and showing them "not registered" would be inventing an answer.
        ->assertJsonPath('data.registration.is_registered', null)
        ->assertJsonPath('data.registration.has_certificate', false)
        ->assertJsonPath('data.registration.registration_number_label', null)
        // The form's options come from the server, so the client cannot offer a
        // registration type the API would refuse.
        ->assertJsonCount(5, 'data.types');
});

test('registering stores the type, the number and the certificate', function () {
    Storage::fake('public');

    [$owner, $business] = ws54rBusiness();

    $this->withToken(ws54rToken($owner))
        ->patchJson('/api/v1/management/business/registration', [
            'is_registered' => true,
            'registration_type' => BusinessRegistrationType::LIMITED_COMPANY->value,
            'registration_number' => 'RC 1234567',
            'certificate' => ws54rCertificate(),
        ])
        ->assertOk()
        ->assertJsonPath('data.registration.is_registered', true)
        ->assertJsonPath('data.registration.registration_type', 'limited_company')
        ->assertJsonPath('data.registration.registration_number', 'RC 1234567')
        // The label follows the type: a company's number is an RC, and a form
        // that asked for a BN here would be asking for a number that does not
        // exist on the certificate the owner is holding.
        ->assertJsonPath('data.registration.registration_number_label', 'RC number')
        ->assertJsonPath('data.registration.has_certificate', true);

    $business->refresh();

    expect($business->is_registered)->toBeTrue()
        ->and($business->registration_number)->toBe('RC 1234567')
        ->and($business->certificate_path)->not->toBeNull();

    Storage::disk('public')->assertExists($business->certificate_path);
});

test('a business name registration asks for a BN, not an RC', function () {
    Storage::fake('public');

    [$owner] = ws54rBusiness();

    $this->withToken(ws54rToken($owner))
        ->patchJson('/api/v1/management/business/registration', [
            'is_registered' => true,
            'registration_type' => BusinessRegistrationType::BUSINESS_NAME->value,
            'registration_number' => 'BN 7654321',
            'certificate' => ws54rCertificate(),
        ])
        ->assertOk()
        ->assertJsonPath('data.registration.registration_type_label', 'Business Name')
        ->assertJsonPath('data.registration.registration_number_label', 'BN number');
});

test('registering without a certificate is refused, and says which field', function () {
    Storage::fake('public');

    [$owner, $business] = ws54rBusiness();

    $this->withToken(ws54rToken($owner))
        ->patchJson('/api/v1/management/business/registration', [
            'is_registered' => true,
            'registration_type' => BusinessRegistrationType::LIMITED_COMPANY->value,
            'registration_number' => 'RC 1234567',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('certificate');

    expect($business->fresh()->is_registered)->toBeNull();
});

test('a later save does not demand the certificate again', function () {
    Storage::fake('public');

    [$owner, $business] = ws54rBusiness();

    $token = ws54rToken($owner);

    $this->withToken($token)->patchJson('/api/v1/management/business/registration', [
        'is_registered' => true,
        'registration_type' => BusinessRegistrationType::LIMITED_COMPANY->value,
        'registration_number' => 'RC 1234567',
        'certificate' => ws54rCertificate(),
    ])->assertOk();

    $stored = $business->fresh()->certificate_path;

    // Correcting the number on its own. A required certificate would send the
    // owner looking for the file to change one digit.
    $this->withToken($token)->patchJson('/api/v1/management/business/registration', [
        'is_registered' => true,
        'registration_type' => BusinessRegistrationType::LIMITED_COMPANY->value,
        'registration_number' => 'RC 7654321',
    ])
        ->assertOk()
        ->assertJsonPath('data.registration.registration_number', 'RC 7654321')
        ->assertJsonPath('data.registration.has_certificate', true);

    expect($business->fresh()->certificate_path)->toBe($stored);
    Storage::disk('public')->assertExists($stored);
});

test('answering no clears the number, the type and the certificate', function () {
    Storage::fake('public');

    [$owner, $business] = ws54rBusiness();

    $token = ws54rToken($owner);

    $this->withToken($token)->patchJson('/api/v1/management/business/registration', [
        'is_registered' => true,
        'registration_type' => BusinessRegistrationType::PARTNERSHIP->value,
        'registration_number' => 'PART-1',
        'certificate' => ws54rCertificate(),
    ])->assertOk();

    $stored = $business->fresh()->certificate_path;

    $this->withToken($token)->patchJson('/api/v1/management/business/registration', [
        'is_registered' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.registration.is_registered', false)
        ->assertJsonPath('data.registration.registration_type', null)
        ->assertJsonPath('data.registration.registration_number', null)
        ->assertJsonPath('data.registration.has_certificate', false);

    $business->refresh();

    expect($business->registration_type)->toBeNull()
        ->and($business->registration_number)->toBeNull()
        ->and($business->certificate_path)->toBeNull();

    // The file goes too, on disk and not only in the row — a certificate left
    // behind for a business that says it is not registered is evidence for a
    // claim nobody is making.
    Storage::disk('public')->assertMissing($stored);
});

test('a replacement certificate is stored and the old one removed', function () {
    Storage::fake('public');

    [$owner, $business] = ws54rBusiness();

    $token = ws54rToken($owner);

    $this->withToken($token)->patchJson('/api/v1/management/business/registration', [
        'is_registered' => true,
        'registration_type' => BusinessRegistrationType::LIMITED_COMPANY->value,
        'registration_number' => 'RC 1234567',
        'certificate' => ws54rCertificate('first.png'),
    ])->assertOk();

    $first = $business->fresh()->certificate_path;

    $this->withToken($token)->patchJson('/api/v1/management/business/registration', [
        'is_registered' => true,
        'registration_type' => BusinessRegistrationType::LIMITED_COMPANY->value,
        'registration_number' => 'RC 1234567',
        'certificate' => ws54rCertificate('second.png'),
    ])->assertOk();

    $second = $business->fresh()->certificate_path;

    expect($second)->not->toBe($first);

    Storage::disk('public')->assertExists($second);
    Storage::disk('public')->assertMissing($first);
});

test('the registration fields are validated rather than accepted', function () {
    [$owner] = ws54rBusiness();
    $token = ws54rToken($owner);

    // Not answering the question at all.
    $this->withToken($token)
        ->patchJson('/api/v1/management/business/registration', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_registered');

    // Registered, but as nothing and under nothing.
    $this->withToken($token)
        ->patchJson('/api/v1/management/business/registration', ['is_registered' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['registration_type', 'registration_number']);

    // A type the registry does not have.
    $this->withToken($token)
        ->patchJson('/api/v1/management/business/registration', [
            'is_registered' => true,
            'registration_type' => 'sole_trader_of_the_year',
            'registration_number' => 'X-1',
            'certificate' => ws54rCertificate(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_type');
});

test('another business cannot read or write these details', function () {
    Storage::fake('public');

    [$owner, $business] = ws54rBusiness();
    [$otherOwner, $otherBusiness] = ws54rBusiness();

    $this->withToken(ws54rToken($otherOwner))
        ->patchJson('/api/v1/management/business/registration', [
            'is_registered' => true,
            'registration_type' => BusinessRegistrationType::LIMITED_COMPANY->value,
            'registration_number' => 'RC 9999999',
            'certificate' => ws54rCertificate(),
        ])
        ->assertOk();

    // The other business's row is untouched — the endpoint takes the business
    // from the token and never from the request, so there is no id to aim at.
    expect($business->fresh()->is_registered)->toBeNull()
        ->and($otherBusiness->fresh()->registration_number)->toBe('RC 9999999');
});

test('the registration endpoints require authentication', function () {
    $this->getJson('/api/v1/management/business/registration')->assertUnauthorized();
    $this->patchJson('/api/v1/management/business/registration', [])->assertUnauthorized();
});

test('a retired registration type still reads back', function () {
    // A type that no longer exists in the enum — a business answered before the
    // registry renamed something. The row keeps its value and the form shows
    // the raw string rather than crashing on an unmatched case.
    [$owner, $business] = ws54rBusiness([
        'is_registered' => true,
        'registration_type' => 'sole_proprietorship_legacy',
        'registration_number' => 'OLD-1',
    ]);

    $this->withToken(ws54rToken($owner))
        ->getJson('/api/v1/management/business/registration')
        ->assertOk()
        ->assertJsonPath('data.registration.is_registered', true)
        ->assertJsonPath('data.registration.registration_type', null)
        ->assertJsonPath('data.registration.registration_number', 'OLD-1');
});
