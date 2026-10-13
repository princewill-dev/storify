<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Payout;
use App\Models\PayoutAllocation;
use App\Models\User;
use App\Services\Access\TenantGuard;
use App\Support\Money\Naira;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-49 — the transfers Storify has made to this business's bank.
 *
 * Before this a business could not list, see or name a single payout. The money
 * left, a `payouts` row was written, and the only trace on any screen was the
 * business's bank statement — which is exactly the position the collect-and-pay-
 * out model had to get out of, because a business that cannot see the transfers
 * cannot tell a slow bank from a missing payment.
 *
 * Both payloads are the bank's own vocabulary: where it went, how much, when, and
 * — on the detail — which sales it was made up of. That last part is the one the
 * `payouts` row alone cannot answer, because a payout sends a pooled balance; the
 * {@see PayoutAllocation} rows are what make "this ₦47,500 was these nine sales"
 * true, and they are only readable here.
 *
 * Tenancy is the same three shapes the rest of the API uses, through
 * {@see TenantGuard}: a payout belongs to a business, and it carries a store only
 * when the sweep wrote it for one. A payout with no store is behind the business
 * check alone — for a store-assigned staff member that means their store's sales
 * are in a payout they may not be able to open, which is the honest arrangement
 * for a transfer that is not attributable to a store.
 */
class PayoutController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly TenantGuard $guard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $payouts = $this->query($user)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('store_id'), fn (Builder $q) => $q->where('store_id', (int) $request->integer('store_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('requested_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('requested_at', '<=', $request->date('date_to')))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 20))));

        return $this->ok(
            $payouts->getCollection()->map(fn (Payout $payout) => $this->row($payout))->values()->all(),
            null,
            200,
            $this->paginationMeta($payouts),
        );
    }

    public function show(Request $request, Payout $payout): JsonResponse
    {
        $this->authorizePayout($request, $payout);

        $payout->load([
            'allocations.transaction:id,reference,amount,currency,paid_at,settle_at,unwound_at,metadata',
            'allocations.store:id,name',
        ]);

        return $this->ok([
            'payout' => $this->row($payout, detail: true)
                + ['allocations' => $this->allocations($payout)],
        ]);
    }

    /**
     * The destination columns are copied onto each payout row at the moment it is
     * reserved, so this reads them rather than the business's current bank: a
     * transfer that has already left has to keep saying where it went, even after
     * the business changes its account.
     *
     * The account number is not masked here. Everything in this payload is the
     * business's own record of its own money sent to its own account, and a
     * business checking whether the right account was paid needs the digits it
     * will compare against — masking them would make the one question this screen
     * exists to answer unanswerable. What is *not* here is any other business's
     * data, which the tenancy guard enforces.
     *
     * @return array<string, mixed>
     */
    private function row(Payout $payout, bool $detail = false): array
    {
        $row = [
            'id' => (int) $payout->id,
            'reference' => $payout->reference,
            'status' => $payout->status,
            'currency' => $payout->currency,
            'amount' => Naira::floatFromKobo((int) $payout->amount_kobo),
            'amount_kobo' => (int) $payout->amount_kobo,
            'provider' => $payout->provider,
            'store_id' => $payout->store_id !== null ? (int) $payout->store_id : null,
            'bank_code' => $payout->bank_code,
            'account_number' => $payout->account_number,
            'account_name' => $payout->account_name,
            'failure_code' => $payout->failure_code,
            'failure_reason' => $payout->failure_reason,
            'requested_at' => $payout->requested_at?->toISOString(),
            'sent_at' => $payout->sent_at?->toISOString(),
            'paid_at' => $payout->paid_at?->toISOString(),
            'failed_at' => $payout->failed_at?->toISOString(),
            'reversed_at' => $payout->reversed_at?->toISOString(),
            'created_at' => $payout->created_at?->toISOString(),
        ];

        if (! $detail) {
            return $row;
        }

        return $row + [
            // How many sales the amount was drawn from. The list does not carry
            // it — it is a second query per row for a column no list renders.
            'sales_count' => $payout->allocations->count(),
            // Null when the scheduled sweep asked for it, which is the ordinary
            // case; a person's name means someone intervened.
            'requested_by' => $payout->requested_by !== null ? (int) $payout->requested_by : null,
        ];
    }

    /**
     * The sales behind the amount, newest rule first: the walk takes them oldest
     * first, and each line is that sale's own net — never the `net_kobo` column,
     * which overstates a managed sale by the commission.
     *
     * A line's `amount_kobo` can be **less** than the sale's net, and that is a
     * designed state rather than a rounding artefact: a refund after a payout
     * drives the wallet negative and the sales that recover it part-fund the next
     * transfer, so one sale can be split across a payout boundary. The sum of
     * these lines is exactly the payout's amount, which is the property that makes
     * this list an explanation rather than an approximation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function allocations(Payout $payout): array
    {
        return $payout->allocations
            ->map(function (PayoutAllocation $allocation) {
                $transaction = $allocation->transaction;

                return [
                    'transaction_id' => (int) $allocation->transaction_id,
                    'reference' => $transaction?->reference,
                    'store_id' => $allocation->store_id !== null ? (int) $allocation->store_id : null,
                    'store_name' => $allocation->store?->name,
                    'status' => $allocation->status,
                    'amount' => Naira::floatFromKobo((int) $allocation->amount_kobo),
                    'amount_kobo' => (int) $allocation->amount_kobo,
                    'sale_amount' => $transaction !== null ? (float) $transaction->amount : null,
                    'sale_paid_at' => $transaction?->paid_at?->toISOString(),
                    'settled_at' => $allocation->settled_at?->toISOString(),
                    'released_at' => $allocation->released_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    private function query(User $user): Builder
    {
        return Payout::query()
            ->where('business_id', $user->business_id)
            // A store-assigned staff member sees the payouts for their stores;
            // a business-level payout (no store) is visible to them too, because
            // its amount includes their store's sales and hiding it would leave
            // money on their screen that nothing accounts for.
            ->when(
                $user->isStaff() && $user->assignedStores()->exists(),
                fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                    ->whereIn('store_id', $user->assignedStores()->pluck('stores.id'))
                    ->orWhereNull('store_id')),
            );
    }

    private function authorizePayout(Request $request, Payout $payout): void
    {
        $user = $this->user($request);

        $this->guard->authorizeBusiness($payout, $user, 'This payout does not belong to your business.');

        if ($payout->store_id !== null) {
            $this->guard->authorizeStoreId($user, (int) $payout->store_id, 'You do not have access to this payout.');
        }
    }
}
