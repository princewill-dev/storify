<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\BelongsToBusiness;

class Location extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'location_code', 'user_id', 'name', 'address',
        'city', 'state', 'country', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->location_code)) {
                $model->location_code = 'loc_' . Str::lower(Str::random(10));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'location_code';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
