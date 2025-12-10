<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KycDocumentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function vendorKycApplications(): HasMany
    {
        return $this->hasMany(VendorKycApplication::class, 'kyc_document_type_id');
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'kyc_document_type_id');
    }
}
