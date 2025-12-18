<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EarlyPass extends Model
{
    protected $fillable = [
        'code',
        'description',
        'is_used',
        'used_by_vendor_id',
        'used_at',
    ];

    protected $casts = [
        'is_used' => 'boolean',
        'used_at' => 'datetime',
    ];

    /**
     * Mark this pass as used by a vendor.
     */
    public function markAsUsed(int $vendorId): void
    {
        $this->update([
            'is_used' => true,
            'used_by_vendor_id' => $vendorId,
            'used_at' => now(),
        ]);
    }

    /**
     * Check if the pass is still available.
     */
    public function isAvailable(): bool
    {
        return !$this->is_used;
    }
}
