<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'company_description',
        'company_logo_path',
        'company_favicon_path',
        'company_certificate_path',
        'support_email',
        'support_phone',
        'company_address',
        'branch_address',
        'store_creation_limit',
        'api_keys',
        'main_store_id',
        'trial_enabled',
        'trial_days',
        // SEO
        'og_title',
        'og_description',
        'og_image_path',
        'og_url',
        'og_type',
        // Greeting Modal
        'greeting_modal_enabled',
        'greeting_modal_frequency',
    ];

    protected $casts = [
        'api_keys' => 'array',
        'main_store_id' => 'integer',
        'greeting_modal_enabled' => 'boolean',
        'store_creation_limit' => 'integer',
        'trial_enabled' => 'boolean',
        'trial_days' => 'integer',
    ];
}
