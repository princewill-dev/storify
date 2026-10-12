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
        // Which gateway the platform bills its own subscriptions through.
        'billing_gateway',
        // The one processor Storify takes every business's customer money
        // through, or null for the platform's original behaviour: each business
        // charges through whatever it has connected. See `SystemProcessor` —
        // null is not "unset", it is "off".
        'system_payment_processor',
        // Storify's commission on a sale it processed on a business's behalf:
        // a percentage and a per-sale fixed amount, in kobo.
        'platform_commission_percent',
        'platform_commission_fixed_kobo',
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
        // Read as a float rather than a string: `PlatformCommission` does its
        // arithmetic in integer basis points, and handing it a decimal string
        // only to cast it there would put the same cast in two places.
        'platform_commission_percent' => 'float',
        'platform_commission_fixed_kobo' => 'integer',
    ];
}
