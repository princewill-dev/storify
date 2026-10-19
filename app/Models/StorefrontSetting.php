<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontSetting extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'store_id',
        'site_title',
        'meta_description',
        'og_image_path',
        'template_key',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
