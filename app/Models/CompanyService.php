<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyService extends Model
{
    use HasFactory;

    protected $fillable = [
        'order',
        'title',
        'description',
        'page_link',
        'background_image_path',
        'status',
    ];

    /**
     * Default ordering scope
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}
