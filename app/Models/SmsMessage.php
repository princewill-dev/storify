<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    use BelongsToBusiness;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const TYPE_ORDER_PAID_OWNER = 'order.paid.owner';

    public const TYPE_ORDER_PAID_CUSTOMER_FIRST = 'order.paid.customer_first';

    public const TYPE_ORDER_STATUS_CUSTOMER = 'order.status.customer';

    public const TYPE_INVOICE_PAID_OWNER = 'invoice.paid.owner';

    protected $fillable = [
        'business_id',
        'user_id',
        'customer_id',
        'phone',
        'type',
        'dedupe_key',
        'message',
        'status',
        'provider_message_id',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
