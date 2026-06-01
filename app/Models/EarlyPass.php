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
     * Mark this pass as used by a vendor.
     * @deprecated Use usages() relationship instead.
     */
    public function markAsUsed(int $vendorId, ?int $storeId = null): void
    {
        $this->usages()->create([
            'user_id' => $vendorId,
            'store_id' => $storeId,
            'used_at' => now(),
        ]);

        if (!is_null($this->max_uses) && $this->usages()->count() >= $this->max_uses) {
            $this->update(['is_active' => false]);
        }
    }

    /**
     * Check if the pass is still available.
     */
    public function isAvailable(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!is_null($this->max_uses) && $this->usages()->count() >= $this->max_uses) {
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

    /**
     * Get the vendor who used this pass.
     * @deprecated Use usages() relationship instead.
     */
    public function usedByVendor()
    {
        // For Backward Compatibility in case Admin Index uses it, 
        // return HasOneThrough or similar, or just return empty/null or throw?
        // Admin index uses `with('usedByVendor')`.
        // I should update Admin Index to use `withCount('usages')` instead.
        // But for now, returning null relation or empty might break eager load?
        // Relation methods must return Relation instance.
        // I'll return a dummy relation or update AdminController IMMEDIATELY.
        // I WILL UPDATE ADMIN CONTROLLER IMMEDIATELY.
        return $this->hasOne(EarlyPassUsage::class)->latest(); // Return latest usage as "used by"?
    }
}
