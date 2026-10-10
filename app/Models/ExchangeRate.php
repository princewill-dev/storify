<?php

namespace App\Models;

use App\Support\Money\ExchangeRateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One currency pair's rate, as last fetched.
 *
 * `rate` is units of `quote` per one `base`. Reached through
 * {@see ExchangeRateService}, which is the only thing that
 * should read these rows — the arithmetic that matters lives there, and a
 * second reader would be a second rounding rule.
 *
 * @property string $base
 * @property string $quote
 * @property string $rate
 * @property Carbon $fetched_at
 */
class ExchangeRate extends Model
{
    protected $fillable = [
        'base',
        'quote',
        'rate',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            // A string, not a float: this is a persisted number that gets
            // multiplied against money, and the cast is what keeps MySQL's
            // decimal from being rounded on the way in.
            'rate' => 'string',
            'fetched_at' => 'datetime',
        ];
    }
}
