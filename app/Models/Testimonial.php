<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = [
        'name',
        'photo',
        'occupation',
        'message',
        'status',
        'position',
    ];

    /**
     * Scope to get only active testimonials
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
