<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound message to one customer.
 *
 * The counterpart to CustomerNote: a note is what the business writes about a
 * customer and never sends, this is what it writes *to* them. Read by the
 * Messages screen and by the customer's own tab; never by the storefront, which
 * reads its mail rather than an API.
 */
class CustomerMessage extends Model
{
    public const CATEGORY_MARKETING = 'marketing';

    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const STATUS_QUEUED = 'queued';

    protected $fillable = [
        'business_id',
        'customer_id',
        'user_id',
        'category',
        'subject',
        'body',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this kind of message is allowed to disregard a refusal.
     *
     * A transactional message answers something the customer did — an order,
     * a complaint, an account change — and a business that cannot reply to the
     * person who wrote to it is not protecting anyone's privacy.
     */
    public function respectsConsent(): bool
    {
        return self::requiresConsent($this->category);
    }

    /**
     * The catalogue, keyed by value: what the compose screen offers, what the
     * request validates against, and what decides whether the audience is
     * filtered by consent. One map because three callers must agree — a
     * category the form offers but the filter does not know about is a
     * marketing send that goes to everyone.
     *
     * @return array<string, array{label: string, requires_consent: bool}>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_MARKETING => ['label' => 'Marketing', 'requires_consent' => true],
            self::CATEGORY_TRANSACTIONAL => ['label' => 'Transactional', 'requires_consent' => false],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function categoryValues(): array
    {
        return array_keys(self::categories());
    }

    public static function requiresConsent(string $category): bool
    {
        return self::categories()[$category]['requires_consent'] ?? false;
    }
}
