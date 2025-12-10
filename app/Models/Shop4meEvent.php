<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop4meEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop4me_request_id', 'created_by', 'type', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function request()
    {
        return $this->belongsTo(Shop4meRequest::class, 'shop4me_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
