<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-59 — the inventory overview window and its location filters.
 *
 * `days` is a closed set for the same two reasons the dashboard trend uses
 * one: the range control offers three windows, and the number is also the
 * number of zero-filled rows the response carries. Accepting `days=3650`
 * would be accepting a request to build a decade of rows from a query string.
 *
 * The two ids are integers validated here and access-checked in the
 * controller — a rule cannot abort with the 403 this endpoint promises, and a
 * 404 would let an id outside the circle be probed.
 */
final class InventoryOverviewRequest extends FormRequest
{
    /**
     * The ranges the overview's control offers, and the only ones accepted.
     */
    public const WINDOWS = [7, 30, 90];

    public const DEFAULT_WINDOW = 30;

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
            'days' => ['nullable', 'integer', Rule::in(self::WINDOWS)],
            'store_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Absent `days` means the middle window, which is what the control opens on.
     */
    public function window(): int
    {
        return $this->filled('days') ? $this->integer('days') : self::DEFAULT_WINDOW;
    }
}
