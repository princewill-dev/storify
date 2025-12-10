<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'country','state','area','fee','delivery_days','active'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
