<?php

namespace App\Services\Management;

use App\Http\Requests\Management\SubmitKycRequest;
use App\Models\KycPhoneVerification;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Sms\TermiiSmsClient;
use App\Support\Sms\PhoneNumber;
use Illuminate\Validation\ValidationException;

/**
 * Prove that the phone on the KYC form belongs to the owner.
 *
 * The OTP is the existing `OtpService` record, with its own type and the
 * normalised number as the identifier. What is new is the send: it happens
 * synchronously, because a code that never arrived must be reported to the
 * person waiting for it, and the OTP row is deleted when Termii refuses, so a
 * failed send does not leave a valid unknown code behind.
 *
 * A successful verification is stored per user + business + number rather than
 * on the user, because the form's phone is the fact being proved — and the same
 * owner may, on a later application, enter a different one.
 */
final class KycPhoneVerificationService
{
    public const OTP_TYPE = 'kyc_phone_verification';

    public const OTP_EXPIRY_MINUTES = 10;

    public function __construct(private readonly TermiiSmsClient $sms) {}

    /**
     * @return array{phone: string, expires_in: int}
     */
    public function send(User $user, ?string $dialCode, string $number): array
    {
        $phone = $this->normalize($dialCode, $number);

        $otp = OtpService::generate($phone, self::OTP_TYPE, self::OTP_EXPIRY_MINUTES);

        $result = $this->sms->send(
            $phone,
            "Your Storify verification code is {$otp->code}. It expires in "
            .self::OTP_EXPIRY_MINUTES.' minutes.',
        );

        if (! $result->ok) {
            $otp->delete();

            throw ValidationException::withMessages([
                'phone_number' => 'We could not send the code by SMS. Please try again.',
            ]);
        }

        return [
            'phone' => $phone,
            'expires_in' => self::OTP_EXPIRY_MINUTES * 60,
        ];
    }

    public function confirm(User $user, ?string $dialCode, string $number, string $code): KycPhoneVerification
    {
        $phone = $this->normalize($dialCode, $number);

        if (! OtpService::verify($phone, $code, self::OTP_TYPE)) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired.',
            ]);
        }

        return KycPhoneVerification::updateOrCreate(
            [
                'user_id' => $user->id,
                'phone' => $phone,
            ],
            [
                'business_id' => $user->business_id,
                'verified_at' => now(),
            ],
        );
    }

    public function verificationFor(User $user, string $phone): ?KycPhoneVerification
    {
        return KycPhoneVerification::query()
            ->where('user_id', $user->id)
            ->where('business_id', $user->business_id)
            ->where('phone', $phone)
            ->first();
    }

    public function latestVerification(User $user): ?KycPhoneVerification
    {
        return KycPhoneVerification::query()
            ->where('user_id', $user->id)
            ->where('business_id', $user->business_id)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->first();
    }

    public function hasVerifiedPhone(User $user): bool
    {
        return KycPhoneVerification::query()
            ->where('user_id', $user->id)
            ->where('business_id', $user->business_id)
            ->exists();
    }

    private function normalize(?string $dialCode, string $number): string
    {
        $phone = PhoneNumber::normalize($dialCode, $number, SubmitKycRequest::DEFAULT_DIAL_CODE);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid phone number.',
            ]);
        }

        return $phone;
    }
}
