<?php

namespace App\Services\Management;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WS-56 — honouring a deletion request.
 *
 * ── Why the customer row is anonymised and not deleted ─────────────────────
 *
 * `orders.customer_id` is a foreign key with `cascadeOnDelete`. Deleting the
 * customer row would take every order that person ever placed with it —
 * silently, from a screen that said "delete this customer". Those are financial
 * records the business is required to keep, and the customer deleting their
 * account does not delete their debt to a supplier or the tax on a sale.
 *
 * So the person is removed from the row and the row stays: names, phone, email,
 * address and IP are cleared, the password is replaced with a random hash, the
 * status becomes `deleted`, and the order history keeps pointing at a tombstone.
 * `MONEY — the orders, their items and their transactions are untouched`, and
 * they are the reason this is an erasure rather than a delete.
 *
 * The email becomes `erased+{account_id}@storify.invalid` rather than null. The
 * column is `NOT NULL` and `(business_id, email)` is unique; `.invalid` is
 * reserved by RFC 2606 and can never resolve, so a tombstone cannot be mailed
 * even if some future query forgets to exclude erased rows.
 *
 * Irreversible, and only ever reached from an explicit approval on the
 * deletion queue.
 *
 * ── What is NOT erased, and why ────────────────────────────────────────────
 *
 * Activity log rows are left alone, including older ones whose `description`
 * contains the customer's name ("Suspended customer: Jane Doe"). This was put
 * to the owner as a choice on 2026-10-15 and decided in favour of the trail:
 * the log is what makes an investigation possible, and the record of what the
 * business did and why protects the *other* party as much as this one. Scrubbing
 * it would destroy the evidence of the decision being carried out here.
 *
 * What this service still refuses to do is *add* personal data to the log. Its
 * own audit row is keyed by `account_id` and counts orders rather than naming
 * anyone, so an erasure is itself recorded without writing back the name that
 * was just removed. The consequence is deliberate and worth stating plainly:
 * after an erasure the name survives in older log rows, and that is the accepted
 * position rather than an oversight. If it is ever revisited, the rows to look
 * at are `activity_logs.description` before this change, not the rows below.
 *
 * The retained orders are the same trade in a different currency. They are held
 * indefinitely because accounting records outlive the account, and the owner's
 * position is that retention should be thorough enough to reconstruct what
 * happened. There is no expiry, so nothing purges them; if a window is ever
 * fixed, it belongs in a scheduled command over fully-anonymised rows, and it
 * should be settled with the accountants before it deletes anything.
 */
final class CustomerErasureService
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    /**
     * Erase the person, keep the money.
     */
    public function approve(Customer $customer, User $actor, Request $request): void
    {
        $accountId = $customer->account_id;
        $businessId = $customer->business_id;

        DB::transaction(function () use ($customer, $actor, $request, $accountId, $businessId) {
            $originalEmail = $customer->email;
            $orders = $customer->orders()->count();

            $customer->forceFill([
                'first_name' => 'Erased',
                'last_name' => 'Customer',
                'email' => 'erased+'.$accountId.'@storify.invalid',
                // A random hash, not null: the column is NOT NULL, and if any
                // future code path forgets the status check, nobody can sign in
                // to this row because nobody knows this password.
                'password' => bcrypt(Str::random(48)),
                // Empty, not null: `phone` is NOT NULL in this schema, and a
                // fabricated number would be worse than a blank — it would be
                // somebody's real number, dialled by whoever reads the row
                // next. A tombstone has to be unmistakably not a phone number.
                'phone' => '',
                'ip_address' => null,
                'location' => null,
                'street_address' => null,
                'city' => null,
                'state' => null,
                'country' => null,
                'email_verified_at' => null,
                'status' => Customer::STATUS_DELETED,
            ])->save();

            // Both kinds: the customer's saved book and the per-order snapshots.
            // `orders.delivery_address_id` is nullOnDelete, so no order loses
            // its row — only the pointer, which is the address's whole content
            // and therefore the thing being erased.
            $customer->deliveryAddresses()->delete();

            // Codes in flight for the old address. Deleted rather than
            // invalidated: they are keyed on the address that no longer exists
            // here, and leaving them would leave a way back in for whoever
            // holds that mailbox.
            Otp::query()
                ->where('identifier', $originalEmail)
                ->where('business_id', $businessId)
                ->delete();

            // Every session ends. A request already does this; an approval may
            // follow a request made weeks ago, so it is done again.
            $this->tokens->revokeAllAccessTokens($customer, 'customer');
            $this->refreshTokens->revokeAllFor($customer, 'customer');

            ActivityLog::create([
                'user_id' => $actor->id,
                'business_id' => $businessId,
                'action' => 'customer_erased',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                // Keyed by account_id, deliberately: the name and address this
                // row used to hold are the data being erased, and copying them
                // into the audit trail would defeat the erasure.
                'description' => "Erased customer {$accountId} on an approved deletion request ({$orders} order(s) retained)",
                'old_values' => ['account_id' => $accountId, 'status' => Customer::STATUS_ACTIVE],
                'new_values' => ['account_id' => $accountId, 'status' => Customer::STATUS_DELETED, 'orders_retained' => $orders],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            Log::info('api.management.customer_erased', [
                'customer_id' => $customer->id,
                'account_id' => $accountId,
                'business_id' => $businessId,
                'user_id' => $actor->id,
                'orders_retained' => $orders,
            ]);
        });
    }

    /**
     * Turn the request down and leave the account as it was.
     *
     * `deletion_requested_at` is cleared, so the queue stops showing a request
     * that has been answered, and the reason is stored on the audit row — the
     * customer asked, and the answer should be on the record rather than only
     * in somebody's inbox. The customer is not e-mailed: a business-framed
     * message needs its own template and the store cannot currently send as
     * itself (see CustomerMessageMail).
     */
    public function reject(Customer $customer, User $actor, string $reason, Request $request): void
    {
        DB::transaction(function () use ($customer, $actor, $reason, $request) {
            $requestedAt = $customer->deletion_requested_at;

            $customer->forceFill(['deletion_requested_at' => null])->save();

            ActivityLog::create([
                'user_id' => $actor->id,
                'business_id' => $customer->business_id,
                'action' => 'customer_deletion_rejected',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Deletion request from {$customer->account_id} declined: {$reason}",
                'old_values' => ['deletion_requested_at' => $requestedAt?->toISOString()],
                'new_values' => ['deletion_requested_at' => null, 'reason' => $reason],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        });
    }
}
