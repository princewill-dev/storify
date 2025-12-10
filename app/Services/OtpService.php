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
     * @param string $identifier Email or phone
     * @param string $type Type of OTP (login, password_reset, etc.)
     * @param int $expiryMinutes Expiry time in minutes (default 10)
     * @return Otp
     */
    public static function generate(
        string $identifier,
        string $type = 'login',
        int $expiryMinutes = 10
    ): Otp {
        // Invalidate any existing unverified OTPs for this identifier and type
        Otp::where('identifier', $identifier)
            ->where('type', $type)
            ->where('is_verified', false)
            ->where('expires_at', '>', now())
            ->delete();

        // Generate random 6-digit code
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Create OTP record
        $otp = Otp::create([
            'identifier' => $identifier,
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
     * @param string $identifier Email or phone
     * @param string $code The OTP code to verify
     * @param string $type Type of OTP
     * @return bool
     */
    public static function verify(
        string $identifier,
        string $code,
        string $type = 'login'
    ): bool {
        $otp = Otp::where('identifier', $identifier)
            ->where('code', $code)
            ->where('type', $type)
            ->where('is_verified', false)
            ->first();

        if (!$otp) {
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
     *
     * @param string $identifier
     * @return string
     */
    protected static function maskIdentifier(string $identifier): string
    {
        // For email: show first 2 chars and domain
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $identifier);
            $localPart = $parts[0];
            $domain = $parts[1] ?? '';
            
            if (strlen($localPart) > 2) {
                $masked = substr($localPart, 0, 2) . str_repeat('*', strlen($localPart) - 2);
                return $masked . '@' . $domain;
            }
        }

        // For phone or other: show first 3 and last 2
        if (strlen($identifier) > 5) {
            return substr($identifier, 0, 3) . str_repeat('*', strlen($identifier) - 5) . substr($identifier, -2);
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
