<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EarlyPass extends Model
{
    protected $fillable = [
        'code',
        'description',
        'max_uses',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_uses' => 'integer',
    ];

    public function getRouteKeyName()
    {
        return 'code';
    }

    /**
     * Mark this pass as used by a business user.
     */
    public function markAsUsed(int $userId, ?int $storeId = null): void
    {
        $this->usages()->create([
            'user_id' => $userId,
            'store_id' => $storeId,
            'used_at' => now(),
        ]);

        if (! is_null($this->max_uses) && $this->usages()->count() >= $this->max_uses) {
            $this->update(['is_active' => false]);
        }
    }

    /**
     * Check if the pass is still available.
     */
    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! is_null($this->max_uses) && $this->usages()->count() >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Get usages of this pass.
     */
    public function usages()
    {
        return $this->hasMany(EarlyPassUsage::class);
    }
}
