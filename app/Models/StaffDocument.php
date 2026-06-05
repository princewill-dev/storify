<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use App\Models\BelongsToBusiness;

class StaffDocument extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'user_id', 'file_name', 'file_path', 'original_name',
        'mime_type', 'size', 'tag',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function extension(): string
    {
        return strtoupper(pathinfo($this->file_name, PATHINFO_EXTENSION));
    }

    public function formattedSize(): string
    {
        return match (true) {
            $this->size >= 1048576 => number_format($this->size / 1048576, 1) . ' MB',
            $this->size >= 1024 => number_format($this->size / 1024, 1) . ' KB',
            default => $this->size . ' B',
        };
    }
}
