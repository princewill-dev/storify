<?php

namespace App\Services\Payments\Data;

use App\Enums\InitializationMode;

/**
 * What the customer does next.
 *
 * REDIRECT carries a URL to send them to. OFFLINE carries instructions to show
 * them — which is how manual bank transfer participates without the interface
 * needing a `NotImplemented` arm or the caller needing to know the provider.
 */
final class InitializationResult
{
    /**
     * @param  array<int, array<string, string>>  $instructions  [['label' => ..., 'value' => ...]]
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly bool $success,
        public readonly InitializationMode $mode,
        public readonly ?string $redirectUrl = null,
        public readonly array $instructions = [],
        public readonly ?string $providerReference = null,
        public readonly ?string $providerId = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public static function redirect(string $redirectUrl, ?string $providerReference = null, ?string $providerId = null, array $raw = []): self
    {
        return new self(
            success: true,
            mode: InitializationMode::REDIRECT,
            redirectUrl: $redirectUrl,
            providerReference: $providerReference,
            providerId: $providerId,
            raw: $raw,
        );
    }

    /**
     * @param  array<int, array<string, string>>  $instructions
     */
    public static function offline(array $instructions, ?string $providerReference = null, ?string $message = null, array $raw = []): self
    {
        return new self(
            success: true,
            mode: InitializationMode::OFFLINE,
            instructions: $instructions,
            providerReference: $providerReference,
            message: $message,
            raw: $raw,
        );
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(success: false, mode: InitializationMode::OFFLINE, message: $message, raw: $raw);
    }

    public function requiresRedirect(): bool
    {
        return $this->success && $this->mode === InitializationMode::REDIRECT && $this->redirectUrl !== null;
    }
}
