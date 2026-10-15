<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * WS-12 — a bulk accept or cancel, over a chosen set of orders.
 *
 * Only the two transitions that take no per-order input are here. Accept and
 * cancel are each one button on a queue of orders; dispatch needs a delivery
 * agent, deliver needs a status, return needs a reason — those are decisions
 * about a single order, and a bulk form that collects them is a form pretending
 * to be a list.
 *
 * The cap matters less than it does for invoices, where each id queues an
 * email. It is here for the same reason the products bulk cap is: one request
 * should be a considered batch, not an unbounded loop over whatever a caller
 * sends.
 */
final class BulkOrderActionRequest extends FormRequest
{
    /** The most orders one request may act on. */
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
            'order_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'order_ids.*' => ['integer', 'min:1'],
            // Only `bulk-cancel` reads this; the rule is shared so both
            // endpoints state the same contract.
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return Collection<int, int>
     */
    public function orderIds(): Collection
    {
        return collect($this->validated()['order_ids'])->map(fn ($id) => (int) $id)->unique()->values();
    }

    public function reason(): ?string
    {
        return $this->validated()['reason'] ?? null;
    }
}
