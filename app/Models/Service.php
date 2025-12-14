<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'service_code',
        'name',
        'slug',
        'description',
        'amount',
        'currency_id',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->slug) && !empty($model->name)) {
                $model->slug = Str::slug($model->name) . '-' . substr((string) Str::uuid(), 0, 8);
            }
            if (empty($model->service_code)) {
                $model->service_code = 'svc_' . strtoupper(Str::random(8));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'service_code';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ServiceImage::class)->orderBy('position');
    }

    public function primaryImage(): ?ServiceImage
    {
        $img = $this->images->firstWhere('is_primary', true);
        return $img ?: $this->images->first();
    }
}
