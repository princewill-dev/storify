<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'email',
        'phone',
        'message',
        'status',
        'reply',
        'replied_by_type',
        'replied_by_id',
        'replied_at',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
    ];

    /**
     * Get the store that owns the message
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Scope to get pending messages
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get replied messages
     */
    public function scopeReplied($query)
    {
        return $query->where('status', 'replied');
    }
}
