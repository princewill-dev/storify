<?php

namespace App\Services\Sms;

/**
 * The outcome of one Termii call.
 *
 * A value object rather than an exception because every caller treats a failed
 * SMS as a recorded fact — an OTP the user must retry, an alert row that says
 * `failed` — never as a reason to unwind a payment.
 */
final class SmsSendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(string $messageId): self
    {
        return new self(true, $messageId);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
