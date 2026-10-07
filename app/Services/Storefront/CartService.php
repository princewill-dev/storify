<?php

namespace App\Services\Storefront;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Repositories\Storefront\CartRepository;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;

/**
 * The storefront cart write workflow.
 *
 * Every mutation changes a cart line and then recomputes the cart totals
 * (`Cart::recalcTotals()` sums the lines into `carts.subtotal`, `total` and
 * `item_count`), so the line write and the totals write stay together here.
 * `addItem` keeps the controller's transaction boundary at exactly the point
 * it was: opened after the store-scoped product read and the stock check,
 * closed before the controller reads `$cart->fresh()` for the response.
 *
 * Add-to-cart is the only flow with real branching, moved verbatim: an
 * existing line is updated (a digital product collapses to qty 1; a physical
 * one stacks quantity and re-prices at the line's stored unit price), a new
 * line is inserted, and the bulk price replaces the unit price when the
 * requested quantity reaches the product's bulk threshold. Nothing was
 * reordered.
 *
 * MONEY — `products.amount` is a decimal(12,2) naira column, so a fetched
 * product always presents naira with a decimal point. For that form the
 * controller's inline `(int) round((float) $raw * 100)` is exactly
 * Naira::koboFromRounded's contract, and `unitAmountKobo()` uses it. The
 * no-dot branch (`(int) $raw`) is deliberately preserved: it treats an
 * already-kobo value as kobo, which no single Naira contract does — collapsing
 * the two branches into koboFromRounded would multiply a no-dot value by 100.
 * The bulk unit price is the same float multiply-and-round, so it rides the
 * same contract.
 */
final class CartService
{
    public function __construct(
        private readonly CartRepository $repository,
    ) {}

    /**
     * Adds `$qty` of a product to the cart, matching the line on product and
     * variant key. The lookup, the line write and the recalc are one atomic
     * unit — the controller's DB::transaction moved here intact.
     */
    public function addItem(Cart $cart, Product $product, ?string $variantKey, int $qty): void
    {
        DB::transaction(function () use ($cart, $product, $variantKey, $qty) {
            $line = $this->repository->findLine($cart, $product, $variantKey);
            $unit = $this->unitAmountKobo($product, $qty);

            if ($product->is_digital && $line) {
                // A digital purchase is one licence per line: adding again
                // re-prices the line instead of stacking quantity.
                $line->update(['qty' => 1, 'line_subtotal' => $unit]);
            } elseif ($line) {
                // Existing line: the subtotal re-prices at the unit amount the
                // line was created with, not the freshly computed one.
                $line->update([
                    'qty' => $line->qty + $qty,
                    'line_subtotal' => ($line->qty + $qty) * $line->unit_amount,
                ]);
            } else {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'variant_key' => $variantKey,
                    'name' => $product->name,
                    'unit_amount' => $unit,
                    'qty' => $qty,
                    'line_subtotal' => $unit * $qty,
                ]);
            }

            $cart->recalcTotals();
        });
    }

    /**
     * Sets a line's quantity; zero deletes it.
     *
     * The strict `=== 0` comparison is load-bearing and preserved. The
     * `integer` rule admits more than ints — numeric strings, whole floats
     * and `true` — and only an actual int 0 deletes; the string "0" still
     * writes a zero-quantity line, as it did before the refactor. That is why
     * `$qty` is typed `mixed` and handed over uncoerced: a narrower
     * `int|string` would let PHP's weak-mode coercion turn the float 0.0 into
     * int 0 and delete a line the old code kept as a zero-quantity row.
     */
    public function updateItem(Cart $cart, CartItem $item, mixed $qty): void
    {
        if ($qty === 0) {
            $item->delete();
        } else {
            $item->update(['qty' => $qty, 'line_subtotal' => $qty * $item->unit_amount]);
        }

        $cart->recalcTotals();
    }

    /**
     * Removes one line.
     */
    public function removeItem(Cart $cart, CartItem $item): void
    {
        $item->delete();
        $cart->recalcTotals();
    }

    /**
     * Empties the cart. The bulk `items()->delete()` is kept rather than
     * deleting loaded models one by one — same rows, one statement.
     */
    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->recalcTotals();
    }

    /**
     * Unit price in kobo for one product at a given quantity.
     */
    private function unitAmountKobo(Product $product, int $qty): int
    {
        $raw = $product->amount ?? 0;

        // See the class note: the dotted branch is Naira::koboFromRounded's
        // contract; the no-dot branch is a raw kobo cast and stays as it was.
        $unit = is_numeric($raw)
            ? (str_contains((string) $raw, '.') ? Naira::koboFromRounded($raw) : (int) $raw)
            : 0;

        if ($product->bulk_quantity > 0 && $qty >= $product->bulk_quantity && $product->bulk_price > 0) {
            $unit = Naira::koboFromRounded($product->bulk_price / $product->bulk_quantity);
        }

        return $unit;
    }
}
