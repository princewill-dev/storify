<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id','user_id','guest_token','currency','status',
        'item_count','subtotal','discount_total','tax_total','total','meta'
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function recalcTotals(): void
    {
        $subtotal = $this->items->sum('line_subtotal');
        $this->subtotal = $subtotal;
        $this->discount_total = $this->discount_total ?? 0;
        $this->tax_total = $this->tax_total ?? 0;
        $this->total = max(0, $subtotal - (int)$this->discount_total + (int)$this->tax_total);
        $this->item_count = (int) $this->items->sum('qty');
        $this->save();
    }
}
