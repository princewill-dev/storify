<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductFile extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'product_id',
        'business_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'is_primary',
        'position',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'size' => 'integer',
        'position' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->size;

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk ?: 'local')->exists($this->path);
    }

    public function deleteFromDisk(): void
    {
        try {
            Storage::disk($this->disk ?: 'local')->delete($this->path);
        } catch (\Throwable $e) {
            // Non-fatal: the record is removed regardless.
        }
    }
}
