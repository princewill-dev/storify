<?php

namespace App\Services;

use App\Models\Otp;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class OtpService
{
    /**
     * Generate a 6-digit OTP code.
     *
     * `$businessId` scopes the code to one business's customer base, which is
     * what keeps a storefront code from being usable, or even valid, anywhere
     * else. One email may hold a customer account at several businesses, so a
     * code keyed on the address alone proves nothing about which of them the
     * holder is signing into.
     *
     * Omitted — as every management and admin caller omits it — the code is
     * written and matched against unscoped rows only. That is deliberate: it
     * leaves those flows byte-identical to before, and it means a storefront
     * code cannot be replayed into a business login even for the same address.
     *
     * @param  string  $identifier  Email or phone
     * @param  string  $type  Type of OTP (login, password_reset, etc.)
     * @param  int  $expiryMinutes  Expiry time in minutes (default 10)
     * @param  int|null  $businessId  Business this code belongs to, when the flow has one
     */
    public static function generate(
        string $identifier,
        string $type = 'login',
        int $expiryMinutes = 10,
        ?int $businessId = null
    ): Otp {
        // Invalidate any existing unverified OTPs for this identifier, type and
        // scope. The scope matters as much here as in verify(): without it,
        // asking for a storefront code would silently kill an in-flight
        // management code for the same address, and vice versa. An explicit
        // branch rather than `where('business_id', null)`, which does not match
        // in SQL and would quietly invalidate nothing.
        $existing = Otp::where('identifier', $identifier)
            ->where('type', $type)
            ->where('is_verified', false)
            ->where('expires_at', '>', now());

        $businessId === null
            ? $existing->whereNull('business_id')
            : $existing->where('business_id', $businessId);

        $existing->delete();

        // Generate random 6-digit code
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Create OTP record
        $otp = Otp::create([
            'identifier' => $identifier,
            'business_id' => $businessId,
            'code' => $code,
            'type' => $type,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'ip_address' => Request::ip(),
        ]);

        // Log OTP generation (without the actual code)
        Log::info('OTP generated', [
            'identifier' => self::maskIdentifier($identifier),
            'type' => $type,
            'expires_at' => $otp->expires_at->toDateTimeString(),
            'ip_address' => Request::ip(),
        ]);

        return $otp;
    }

    /**
     * Verify an OTP code.
     *
     * `$businessId` must match the scope the code was issued under, so a code
     * generated for one business's customer base cannot be spent on another's.
     * Callers that passed nothing to generate() must pass nothing here too, or
     * their own codes will not be found.
     *
     * @param  string  $identifier  Email or phone
     * @param  string  $code  The OTP code to verify
     * @param  string  $type  Type of OTP
     * @param  int|null  $businessId  Business this code was issued for, when the flow has one
     */
    public static function verify(
        string $identifier,
        string $code,
        string $type = 'login',
        ?int $businessId = null
    ): bool {
        $query = Otp::where('identifier', $identifier)
            ->where('code', $code)
            ->where('type', $type)
            ->where('is_verified', false);

        $businessId === null
            ? $query->whereNull('business_id')
            : $query->where('business_id', $businessId);

        $otp = $query->first();

        if (! $otp) {
            Log::warning('OTP verification failed - code not found', [
                'identifier' => self::maskIdentifier($identifier),
                'type' => $type,
                'ip_address' => Request::ip(),
            ]);

            return false;
        }

        if ($otp->isExpired()) {
            Log::warning('OTP verification failed - code expired', [
                'identifier' => self::maskIdentifier($identifier),
                'type' => $type,
                'expired_at' => $otp->expires_at->toDateTimeString(),
                'ip_address' => Request::ip(),
            ]);

            return false;
        }

        // Mark as verified
        $otp->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        Log::info('OTP verified successfully', [
            'identifier' => self::maskIdentifier($identifier),
            'type' => $type,
            'ip_address' => Request::ip(),
        ]);

        return true;
    }

    /**
     * Mask identifier for logging (hide sensitive parts).
     */
    protected static function maskIdentifier(string $identifier): string
    {
        // For email: show first 2 chars and domain
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $identifier);
            $localPart = $parts[0];
            $domain = $parts[1] ?? '';

            if (strlen($localPart) > 2) {
                $masked = substr($localPart, 0, 2).str_repeat('*', strlen($localPart) - 2);

                return $masked.'@'.$domain;
            }
        }

        // For phone or other: show first 3 and last 2
        if (strlen($identifier) > 5) {
            return substr($identifier, 0, 3).str_repeat('*', strlen($identifier) - 5).substr($identifier, -2);
        }

        return str_repeat('*', strlen($identifier));
    }

    /**
     * Clean up expired OTPs (can be called via scheduled task).
     *
     * @return int Number of deleted records
     */
    public static function cleanupExpired(): int
    {
        $deleted = Otp::where('expires_at', '<', now()->subDay())->delete();

        if ($deleted > 0) {
            Log::info("Cleaned up {$deleted} expired OTP records");
        }

        return $deleted;
    }
}
