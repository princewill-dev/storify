<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-56 — the body of an internal note.
 *
 * Required and length-capped, and that is the whole rule set: a note is free
 * text a staff member writes about a customer, and constraining its shape
 * beyond "there is one and it is not a novel" would only make people write
 * shorter notes.
 *
 * `max:5000` rather than the column's TEXT limit, because a note is meant to be
 * read in a panel. The cap is generous enough that nobody composing a real note
 * meets it.
 */
class CustomerNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller: it is a 403 the controller
        // owns ahead of the write, not a validation gate.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
