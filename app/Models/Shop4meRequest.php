<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Enums\Shop4meRequestStatus;
use App\Enums\Shop4mePaymentStatus;
use App\Models\BelongsToBusiness;

class Shop4meRequest extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'list_id', 'user_id', 'customer_id', 'store_id', 'currency_id',
        'budget_amount', 'notes', 'status', 'delivery_address_id', 'verification_mode',
        'payment_status','paid_at','payment_method','payment_reference','payment_amount',
    ];

    public const STATUS_PENDING = Shop4meRequestStatus::PENDING->value;
    public const STATUS_ACCEPTED = Shop4meRequestStatus::ACCEPTED->value;
    public const STATUS_REJECTED = Shop4meRequestStatus::REJECTED->value;
    public const STATUS_FILLED = Shop4meRequestStatus::FILLED->value;
    public const STATUS_DISPATCHED = Shop4meRequestStatus::DISPATCHED->value;
    public const STATUS_DELIVERED = Shop4meRequestStatus::DELIVERED->value;
    public const STATUS_CLOSED = Shop4meRequestStatus::CLOSED->value;

    // Payment states
    public const PAY_UNPAID = Shop4mePaymentStatus::UNPAID->value;
    public const PAY_PAID = Shop4mePaymentStatus::PAID->value;
    public const PAY_REFUNDED = Shop4mePaymentStatus::REFUNDED->value;

    protected static function boot()
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->list_id)) {
                $model->list_id = self::generateListId();
            }
            if (empty($model->status)) {
                $model->status = self::STATUS_PENDING;
            }
            if (empty($model->payment_status)) {
                $model->payment_status = self::PAY_UNPAID;
            }
        });
    }

    // Relationships
    public function items()
    {
        return $this->hasMany(Shop4meItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    // Use list_id for route model binding so URLs can use the public ULID-like identifier
    public function getRouteKeyName(): string
    {
        return 'list_id';
    }

    protected static function generateListId(): string
    {
        $id = (string) now()->timestamp;
        while (self::where('list_id', $id)->exists()) {
            $id = (string) ((int) $id + 1);
        }
        return $id;
    }
}
