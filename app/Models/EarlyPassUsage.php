<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EarlyPassUsage extends Model
{
    protected $fillable = [
        'early_pass_id',
        'user_id',
        'store_id',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function earlyPass(): BelongsTo
    {
        return $this->belongsTo(EarlyPass::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
