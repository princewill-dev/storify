<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    /**
     * Log an activity.
     *
     * @param  string  $action  The action being performed
     * @param  string|null  $description  Human-readable description
     * @param  array  $metadata  Additional context (exclude sensitive data)
     * @param  int|null  $userId  User ID (defaults to authenticated user)
     */
    public static function log(
        string $action,
        ?string $description = null,
        array $metadata = [],
        ?int $userId = null
    ): ActivityLog {
        // Filter out sensitive keys from metadata
        $metadata = self::filterSensitiveData($metadata);

        return ActivityLog::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log authentication-related activity.
     */
    public static function logAuth(
        string $action,
        ?int $userId = null,
        ?string $description = null
    ): ActivityLog {
        return self::log($action, $description, [], $userId);
    }

    /**
     * Log user creation.
     */
    public static function logUserCreation(
        int $userId,
        string $role,
        ?string $createdBy = null
    ): ActivityLog {
        $description = "User account created with role: {$role}";
        if ($createdBy) {
            $description .= " by {$createdBy}";
        }

        return self::log(
            'user_created',
            $description,
            [
                'role' => $role,
                'created_by' => $createdBy,
            ],
            $userId
        );
    }

    /**
     * Filter out sensitive data from metadata.
     */
    protected static function filterSensitiveData(array $data): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'token',
            'api_key',
            'secret',
            'credit_card',
            'cvv',
            'ssn',
        ];

        return array_filter($data, function ($key) use ($sensitiveKeys) {
            return ! in_array(strtolower($key), $sensitiveKeys);
        }, ARRAY_FILTER_USE_KEY);
    }
}
