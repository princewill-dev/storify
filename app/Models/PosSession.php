<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Enums\PosSessionStatus;
use App\Models\Concerns\BelongsToBusiness;

class PosSession extends Model
{
    use HasFactory, BelongsToBusiness;

    public const STATUS_OPEN = PosSessionStatus::OPEN->value;
    public const STATUS_CLOSED = PosSessionStatus::CLOSED->value;

    protected $fillable = [
        'session_code',
        'store_id',
        'business_id',
        'staff_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'closing_balance_expected',
        'closing_balance_actual',
        'difference',
        'status',
        'notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'integer',
        'closing_balance_expected' => 'integer',
        'closing_balance_actual' => 'integer',
        'difference' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (PosSession $session) {
            if (empty($session->session_code)) {
                $session->session_code = 'pos_' . Str::lower(Str::random(10));
            }
            if (!$session->opened_at) {
                $session->opened_at = now();
            }
            if (!$session->status) {
                $session->status = self::STATUS_OPEN;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'session_code';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function close(int $actualCash, ?string $notes = null): void
    {
        $this->closing_balance_expected = $this->opening_balance + $this->calculateSalesTotal();
        $this->closing_balance_actual = $actualCash;
        $this->difference = $actualCash - $this->closing_balance_expected;
        $this->closed_at = now();
        $this->status = self::STATUS_CLOSED;
        $this->notes = $notes;
        $this->save();
    }

    public function calculateSalesTotal(): int
    {
        return $this->orders()->sum('total');
    }

    public static function statusBadgeData(): array
    {
        return PosSessionStatus::badgeData();
    }
}
