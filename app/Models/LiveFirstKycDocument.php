<?php

namespace App\Models;

use App\Enums\KycDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LiveFirstKycDocument extends Model
{
    protected $fillable = [
        'application_id',
        'document_type',
        'file_path',
        'file_name',
        'file_size',
        'verified',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'document_type' => KycDocumentType::class,
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(LiveFirstApplication::class, 'application_id');
    }

    public function getFileUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
