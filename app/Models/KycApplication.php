<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Enums\VendorKycStatus;
use App\Models\Concerns\BelongsToBusiness;

class KycApplication extends Model
{
    use HasFactory, BelongsToBusiness;

    public const STATUS_DRAFT = VendorKycStatus::DRAFT->value;
    public const STATUS_SUBMITTED = VendorKycStatus::SUBMITTED->value;
    public const STATUS_APPROVED = VendorKycStatus::APPROVED->value;
    public const STATUS_REJECTED = VendorKycStatus::REJECTED->value;

    protected $fillable = [
        'user_id',
        'status',
        'legal_name',
        'phone_number',
        'date_of_birth',
        'address_line',
        'city',
        'state',
        'country',
        'identification_document_path',
        'selfie_image_path',
        'kyc_document_type_id',
        'kyc_document_id',
        'device_type',
        'browser',
        'ip_address',
        'selfie_image_path',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'review_notes',
        'reviewed_by',
        'payload',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'payload' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function statusOptions(): array
    {
        return array_combine(array_keys(VendorKycStatus::badgeData()), array_column(VendorKycStatus::badgeData(), 'label')) ?? [];
    }

    public static function statusBadgeData(): array
    {
        return VendorKycStatus::badgeData();
    }

    public function getStatusMetadataAttribute(): array
    {
        return VendorKycStatus::badgeData()[$this->status] ?? ['label' => ucfirst(str_replace('_', ' ', $this->status)), 'class' => 'bg-secondary'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }
}
