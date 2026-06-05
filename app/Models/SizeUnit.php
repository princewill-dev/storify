<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizeUnit extends Model
{
    protected $table = 'size_units';

    protected $fillable = ['name', 'code'];

    public $timestamps = false;
}
