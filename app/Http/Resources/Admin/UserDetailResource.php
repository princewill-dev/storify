<?php

namespace App\Http\Resources\Admin;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WS-8 (admin console) — the detail console payload.
 *
 * Legacy rendered the account, business metrics, subscription + last payments
 * and the per-user activity feed in one page; the drawer-only API lost five of
 * the six blocks. The business counts arrive eager-loaded by
 * UserModerationRepository::loadDetailRelations(); the store lists, payments,
 * activity feed and live impersonation arrive as the raw rows that repository
 * method assembles, so response shaping issues no queries of its own.
 */
final class UserDetailResource extends UserResource
{
    /**
     * @param  array{
     *     stores: Collection<int, Store>,
     *     business_stores: Collection<int, Store>|null,
     *     orders_count: int,
     *     payments: Collection<int, Payment>,
     *     activity: Collection<int, ActivityLog>,
     *     active_impersonation: Impersonation|null
     * }  $blocks
     */
    public function __construct(User $user, private readonly array $blocks)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $business = $user->business;
        $subscription = $business?->activeSubscription;

        return parent::toArray($request) + [
            'location' => $user->location,
            'ip_address' => $user->ip_address,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'trial_ends_at' => $user->trial_ends_at?->toISOString(),
            'stores' => $this->stores($this->blocks['stores']),
            'business_detail' => $business === null ? null : [
                'id' => $business->id,
                'name' => $business->name,
                'business_code' => $business->business_code,
                'status' => $business->status,
                'stores_count' => (int) ($business->stores_count ?? 0),
                'warehouses_count' => (int) ($business->warehouses_count ?? 0),
                'team_count' => (int) ($business->team_count ?? 0),
                'orders_count' => $this->blocks['orders_count'],
                'stores' => $this->stores($this->blocks['business_stores'] ?? collect()),
            ],
            'subscription' => $business === null ? null : [
                'status' => $subscription?->status,
                'plan' => $subscription?->subscriptionPlan?->name,
                'subscription_code' => $subscription?->subscription_code,
                'expires_at' => $subscription?->expires_at?->toISOString(),
                'is_trial' => $this->onTrial($user),
                'trial_ends_at' => $user->trial_ends_at?->toISOString(),
            ],
            'payments' => $this->payments(),
            'activity' => $this->activityFeed(),
            'active_impersonation' => $this->activeImpersonation(),
        ];
    }

    /**
     * The store mini-rows shared by the user block and the business block.
     *
     * @param  Collection<int, Store>  $stores
     * @return array<int, array<string, mixed>>
     */
    private function stores(Collection $stores): array
    {
        return $stores->map(fn (Store $store) => [
            'id' => $store->id,
            'name' => $store->name,
            'status' => $store->status,
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payments(): array
    {
        return $this->blocks['payments']->map(fn (Payment $payment) => [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_type' => $payment->payment_type,
            'paid_at' => $payment->paid_at?->toISOString(),
            'created_at' => $payment->created_at?->toISOString(),
        ])->values()->all();
    }

    /**
     * Legacy's per-user feed: rows the user acted on plus rows about the user.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityFeed(): array
    {
        return $this->blocks['activity']->map(fn (ActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'ip_address' => $log->ip_address,
            'actor' => $log->user?->name,
            'created_at' => $log->created_at?->toISOString(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeImpersonation(): ?array
    {
        $impersonation = $this->blocks['active_impersonation'];

        if ($impersonation === null) {
            return null;
        }

        return [
            'id' => $impersonation->id,
            'impersonator' => $impersonation->impersonator?->name,
            'started_at' => $impersonation->started_at?->toISOString(),
        ];
    }

    private function onTrial(User $user): bool
    {
        return $user->trial_ends_at !== null && $user->trial_ends_at->isFuture();
    }
}
