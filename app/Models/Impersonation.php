<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Impersonation extends Model
{
    /**
     * The impersonated session carries this ability on its access token, so a
     * frontend can tell it is inside someone else's account without a second
     * round trip.
     */
    public const ABILITY_PREFIX = 'impersonation:';

    protected $fillable = [
        'impersonator_id',
        'impersonated_id',
        'access_token_id',
        'started_at',
        'ended_at',
        'ip_address',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function impersonated(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_id');
    }

    /**
     * The live impersonation behind an access token, if it is one.
     */
    public static function activeForToken(mixed $token): ?self
    {
        $abilities = (array) ($token->abilities ?? []);

        $ability = collect($abilities)->first(
            fn ($ability) => is_string($ability) && str_starts_with($ability, self::ABILITY_PREFIX)
        );

        if (! $ability) {
            return null;
        }

        $impersonation = self::with('impersonator')
            ->find((int) substr($ability, strlen(self::ABILITY_PREFIX)));

        return $impersonation && $impersonation->ended_at === null ? $impersonation : null;
    }
}
