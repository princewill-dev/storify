<?php

namespace App\Http\Resources\Management;

use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-33 — the support message row / thread payload.
 *
 * This is the controller's private `payload()` array, kept key-for-key and in
 * the same order: exact-JSON consumers assert the field order, so the keys may
 * not be sorted or renamed. `replied_at` / `created_at` / `updated_at` stay
 * ISO-8601 strings or null exactly as `toISOString()` produced them, and the
 * nested store mirrors the store row nested inside the message.
 *
 * `replied_by_name` needs the per-batch name map for `replied_by_id`, which
 * has no relation on the shared model: the controller resolves it once per
 * page (SupportMessageRepository::replierNames) and passes it in. The null
 * rule is the original one — only a `business` reply carries a name here.
 *
 * @property-read SupportMessage $resource
 */
final class SupportMessageResource extends JsonResource
{
    /**
     * @param  array<int, string>  $replierNames
     */
    public function __construct(SupportMessage $message, private readonly array $replierNames = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportMessage $message */
        $message = $this->resource;

        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'phone' => $message->phone,
            'message' => $message->message,
            'status' => $message->status,
            'reply' => $message->reply,
            'replied_by_type' => $message->replied_by_type,
            'replied_by_id' => $message->replied_by_id,
            'replied_by_name' => $message->replied_by_type === 'business'
                ? ($this->replierNames[$message->replied_by_id] ?? null)
                : null,
            'replied_at' => $message->replied_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'store' => $message->store ? [
                'id' => $message->store->id,
                'store_id' => $message->store->store_id,
                'name' => $message->store->name,
            ] : null,
        ];
    }
}
