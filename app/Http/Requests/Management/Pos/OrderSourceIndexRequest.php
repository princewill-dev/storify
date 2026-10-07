<?php

namespace App\Http\Requests\Management\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-17 — the `source` filter on the orders list.
 *
 * This is the only rule the endpoint validated inline (`$request->validate()`
 * in OrderSourceController@index). The other query parameters — store_id,
 * status, from, to, q and per_page — were never validated and stay that way:
 * adding rules here would turn inputs the endpoint used to answer (a
 * non-numeric store_id, an out-of-range per_page) into 422s, which is a
 * behaviour change rather than a move. OrderSourceController reads them with
 * the same filled()/integer()/date() semantics the inline query used and hands
 * plain values to OrderSourceRepository.
 */
final class OrderSourceIndexRequest extends FormRequest
{
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
            'source' => ['nullable', 'string', 'max:50'],
        ];
    }
}
