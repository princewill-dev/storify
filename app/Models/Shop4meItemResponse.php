<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop4meItemResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop4me_item_id', 'admin_id', 'type', 'message', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function item()
    {
        return $this->belongsTo(Shop4meItem::class, 'shop4me_item_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
