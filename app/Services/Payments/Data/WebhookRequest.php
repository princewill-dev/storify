<?php

namespace App\Services\Payments\Data;

/**
 * A provider's webhook, kept as the raw body plus headers.
 *
 * The raw body matters: every provider signs the bytes it sent, so re-encoding
 * a decoded array before hashing produces a different signature. Drivers must
 * hash `payload()` verbatim.
 */
final class WebhookRequest
{
    /**
     * @param  array<string, string>  $headers  lower-cased names
     */
    public function __construct(
        private readonly string $payload,
        private readonly array $headers,
    ) {}

    public function payload(): string
    {
        return $this->payload;
    }

    /**
     * Header lookup that ignores case, because providers are inconsistent —
     * `X-Paystack-Signature` and `x-paystack-signature` both appear in the wild.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
