<?php

namespace App\Http\Resources\Admin;

use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-16 (admin console) — the support message row / thread payload.
 *
 * Field names, types and order are load-bearing: exact-JSON assertions depend
 * on them, so `replied_at`/`created_at`/`updated_at` stay ISO-8601 strings or
 * null and the store context mirrors the store row nested inside the message.
 *
 * `replied_by_name` needs the per-batch name map for `replied_by_id`, which
 * has no relation on the shared model: the controller resolves it once per
 * page (SupportMessageRepository::replierNames) and passes it in.
 *
 * `can` is pre-computed action state so the SPA disables buttons instead of
 * round-tripping the refusals (the same rule the reply guard applies). It
 * reads only this row, and the model is shared with the management side, so
 * it stays in the payload.
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
            // Legacy's detail modal rendered "Reply (Admin)" / "Reply (Business)".
            'replied_by_type_label' => match ($message->replied_by_type) {
                'admin' => 'Admin',
                'business' => 'Business',
                null => null,
                default => ucfirst((string) $message->replied_by_type),
            },
            'replied_by_id' => $message->replied_by_id,
            'replied_by_name' => $message->replied_by_id !== null
                ? ($this->replierNames[$message->replied_by_id] ?? null)
                : null,
            'replied_at' => $message->replied_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'store' => $message->store ? [
                'id' => $message->store->id,
                'store_id' => $message->store->store_id,
                'name' => $message->store->name,
                'status' => $message->store->status,
                'business' => $message->store->business ? [
                    'id' => $message->store->business->id,
                    'name' => $message->store->business->name,
                    'business_code' => $message->store->business->business_code,
                ] : null,
            ] : null,
            // Up-front action state, so the SPA disables buttons instead of
            // round-tripping the refusals (the same rule the reply guard applies).
            'can' => [
                'reply' => $message->status === 'pending',
                'close' => $message->status !== 'closed',
                'reopen' => $message->status !== 'pending',
            ],
        ];
    }
}
