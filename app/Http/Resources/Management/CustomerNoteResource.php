<?php

namespace App\Http\Resources\Management;

use App\Models\CustomerNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-56 — an internal note as the Notes panel renders it.
 *
 * The author's name is included and `user_id` is too, so the SPA can decide
 * whether to offer a delete action without a second lookup. The note body is
 * never escaped away or truncated here: it is business-internal text, rendered
 * by a template that escapes it, and shortening it in the API would make the
 * panel the wrong place to read it.
 */
final class CustomerNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerNote $note */
        $note = $this->resource;

        return [
            'id' => $note->id,
            'body' => $note->body,
            'user_id' => $note->user_id,
            'author' => $note->user?->name,
            'created_at' => $note->created_at?->toISOString(),
        ];
    }
}
