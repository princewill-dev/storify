<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $fillable = ['title', 'description', 'icon_path', 'order'];

    protected $casts = [
        'order' => 'integer',
    ];

    public function getIconUrlAttribute(): string
    {
        if (!$this->icon_path) {
            return asset('vendor_files/assets/images/icon-solid.png');
        }

        return asset('storage/' . $this->icon_path);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }
}
