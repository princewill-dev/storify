<?php

namespace App\Http\Requests\Management\Invoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * WS-21 — a bulk send or void, over a chosen set of invoices.
 *
 * The cap is the point of the class. `bulk-send` queues an email per invoice,
 * so an unbounded id list is an unbounded amount of outbound mail from one
 * request — and a bound is the difference between a considered batch and an
 * accident. Two hundred is what a person selects deliberately; anything
 * larger is a filter they should have used instead.
 *
 * The ids are not checked for existence here. They are checked against the
 * caller's own scope in the controller, because "this id does not exist" and
 * "this id is not yours" must answer the same way — anything else turns the
 * endpoint into a probe for other businesses' invoice ids.
 */
final class BulkInvoiceActionRequest extends FormRequest
{
    /** The most invoices one request may act on. */
    public const MAX_IDS = 200;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'invoice_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'invoice_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * The requested ids, as integers, without duplicates.
     *
     * @return Collection<int, int>
     */
    public function invoiceIds(): Collection
    {
        return collect($this->validated()['invoice_ids'])->map(fn ($id) => (int) $id)->unique()->values();
    }
}
