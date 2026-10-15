<?php

namespace App\Http\Requests\Management;

use App\Models\CustomerMessage;
use Illuminate\Validation\Rule;

/**
 * WS-56 — the compose payload, shared by the one-to-one message on a customer's
 * detail screen and the broadcast from the Messages screen.
 *
 * It extends the index request because a broadcast carries two things at once:
 * the message, and the filters that decide who receives it. Those filters are
 * the customer list's, spelled identically, because the sender composes on a
 * list they have already narrowed — an audience resolved from a different
 * filter vocabulary than the one on screen is a send to people the sender never
 * saw. Inheriting them keeps the two in step by construction; the one-to-one
 * endpoint accepts and ignores them.
 *
 * `category` is required and belongs to the customer's own enum, rather than
 * being implied by which endpoint was called. The distinction is load-bearing:
 * a marketing message is filtered by consent when its audience is resolved and
 * carries an opt-out line when it is sent, and a transactional one is neither.
 * Defaulting it would mean a screen that forgot to send it silently classified
 * every message as transactional, which is the classification that skips the
 * consent check.
 *
 * The subject cap matches the column. The body cap is below the column's TEXT
 * limit because this is an e-mail body composed in a textarea, and someone
 * pasting a document into it wants to be told rather than to have it truncated
 * mid-sentence on the way out.
 */
class CustomerMessageRequest extends CustomerAccountIndexRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'category' => ['required', Rule::in(CustomerMessage::categoryValues())],
        ];
    }
}
