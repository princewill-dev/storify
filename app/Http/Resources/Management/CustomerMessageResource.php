<?php

namespace App\Http\Resources\Management;

use App\Models\CustomerMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-56 — a message the business has sent, as the customer's Messages tab and
 * the compose result render it.
 *
 * `status` is always `queued` today, and it is sent anyway: the row records
 * that a message was composed and handed to the queue, and the field is where a
 * future delivery report would land. It is not presented as delivery, and
 * nothing in this system claims to know a message was read — there is no open
 * or click tracking, here or anywhere (see CustomerMessageService).
 *
 * `category_label` comes from the model so the screen holds no second copy of
 * the wording, exactly as the consent resource does with its enum.
 */
final class CustomerMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerMessage $message */
        $message = $this->resource;

        return [
            'id' => $message->id,
            'subject' => $message->subject,
            'body' => $message->body,
            'category' => $message->category,
            'category_label' => CustomerMessage::categories()[$message->category]['label'] ?? $message->category,
            'status' => $message->status,
            'user_id' => $message->user_id,
            'author' => $message->user?->name,
            'sent_at' => $message->sent_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
