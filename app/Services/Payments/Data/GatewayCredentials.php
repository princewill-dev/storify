<?php

namespace App\Services\Payments\Data;

/**
 * One connection's credential values, for one call.
 *
 * Credentials are passed per call rather than held on a gateway object. The
 * legacy `PaystackService::usingGateway()` mutates an injected client, so a
 * second call in the same request silently inherits the first business's keys —
 * a bug waiting for the first queue worker that processes two businesses. An
 * immutable value object removes the possibility instead of documenting it.
 *
 * `__debugInfo()` is overridden because `dd()`, `dump()`, an uncaught exception
 * trace or a `Log::info($credentials)` would otherwise print live payment keys.
 * Anything that needs the real values asks for them by name.
 */
final class GatewayCredentials
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(
        private readonly string $provider,
        private readonly array $values,
        private readonly array $secretKeys = [],
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $secretKeys  field keys the registry marks secret
     */
    public static function make(string $provider, array $values, array $secretKeys = []): self
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $clean[(string) $key] = (string) $value;
            }
        }

        return new self($provider, $clean, array_values($secretKeys));
    }

    public static function empty(string $provider): self
    {
        return new self($provider, []);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Whether every named key is present — used before offering a provider for
     * checkout, so a half-filled connection cannot be charged through.
     *
     * @param  array<int, string>  $requiredKeys
     */
    public function isComplete(array $requiredKeys): bool
    {
        foreach ($requiredKeys as $key) {
            if (! $this->has($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * The shape the legacy `PaystackService::usingGateway()` expects, so the
     * existing client can be wrapped without being rewritten.
     */
    public function toGatewayObject(): object
    {
        return (object) $this->values;
    }

    /**
     * Safe to print: secrets are replaced with a masked hint.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider,
            'values' => $this->masked(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function masked(): array
    {
        $masked = [];

        foreach ($this->values as $key => $value) {
            $masked[$key] = in_array($key, $this->secretKeys, true) ? self::mask($value) : $value;
        }

        return $masked;
    }

    /**
     * Enough to recognise which key is stored, never enough to use it.
     */
    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4).str_repeat('*', 8).substr($value, -4);
    }
}
