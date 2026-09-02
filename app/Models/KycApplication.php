<?php

namespace App\Models;

use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycApplication extends Model
{
    use BelongsToBusiness, HasFactory;

    public const STATUS_DRAFT = KycStatus::DRAFT->value;

    public const STATUS_SUBMITTED = KycStatus::SUBMITTED->value;

    public const STATUS_APPROVED = KycStatus::APPROVED->value;

    public const STATUS_REJECTED = KycStatus::REJECTED->value;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
        return array_combine(array_keys(KycStatus::badgeData()), array_column(KycStatus::badgeData(), 'label')) ?? [];
    }

    public static function statusBadgeData(): array
    {
        return KycStatus::badgeData();
    }

    public function getStatusMetadataAttribute(): array
    {
        return KycStatus::badgeData()[$this->status] ?? ['label' => ucfirst(str_replace('_', ' ', $this->status)), 'class' => 'bg-secondary'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }
}
