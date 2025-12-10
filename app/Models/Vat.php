<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vat extends Model
{
    use HasFactory;

    protected $fillable = ['percentage','active','effective_at'];

    protected $casts = [
        'active' => 'boolean',
        'effective_at' => 'datetime',
    ];

    public function scopeActive($q){ return $q->where('active', true); }

    public static function current(): ?self
    {
        return static::orderByDesc('effective_at')->orderByDesc('id')->first();
    }
}
