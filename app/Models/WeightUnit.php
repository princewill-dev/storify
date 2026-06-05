<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightUnit extends Model
{
    protected $table = 'weight_units';

    protected $fillable = ['name', 'code'];

    public $timestamps = false;
}
