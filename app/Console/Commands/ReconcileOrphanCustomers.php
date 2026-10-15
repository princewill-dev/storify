<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The customer rows that have no business, and why each one is stuck.
 *
 * Registration never wrote `business_id`, so every account created through a
 * storefront before that was fixed is stored with a NULL one and belongs to
 * nobody. {@see \Database\Migrations\...backfill_customer_business_from_orders}
 * repairs the ones it can prove — a row whose orders all belong to one
 * business, where that business has no customer on the same email — and
 * deliberately leaves the rest alone.
 *
 * This command is the report for the rest. It reads and prints; it writes
 * nothing. Merging a customer is not something a command should decide: the
 * choice is between "these two rows are the same person, fold one into the
 * other and move the orders" and "these are two people who share an email",
 * and only somebody who can look at the orders can tell which. Doing it
 * unattended is how a platform silently hands one person's order history to
 * another.
 *
 * ## The buckets
 *
 * - `backfillable` — a single business, no clash, and still NULL. The migration
 *   should have taken these, so their presence means orders were attached to
 *   the row *after* it ran. The safe repair is to run the migration again, not
 *   to act on this report.
 * - `collision` — a single business, but `(business_id, email)` is already held
 *   by another customer of that business. Usually the same person: they signed
 *   up properly at some point after the orphan row was made by checkout. The
 *   two rows' orders are the thing to look at before folding one in.
 * - `multi_business` — orders spanning more than one business, so there is no
 *   single answer. This happens when one email checked out at two storefronts
 *   that shared their rows before the tenancy was enforced. Each order carries
 *   its own `business_id` and stays correct where it is; what is unresolved is
 *   only which business the *person* belongs to, and the answer is usually to
 *   split them: one row per business, with each business's orders moved onto
 *   its own.
 * - `no_orders` — no order anywhere carries a tenant signal. Nothing in the row
 *   says which business it was made at, and guessing is the defect being
 *   repaired. These stay NULL and are consequently unloginable, which costs
 *   nothing: the person can check out again and be matched by
 *   `(business_id, email)`.
 * - `no_email` — no address to match on at all.
 *
 * ## Exit code
 *
 * Always zero, even when rows are reported. This is a report, and a non-zero
 * exit is what a shell reads as "the step failed" — a deploy that runs this
 * after the migration would stop on the news that orphans exist, which is the
 * news it ran to get. Read the output, not the status.
 */
class ReconcileOrphanCustomers extends Command
{
    protected $signature = 'customers:reconcile-orphans
        {--json : Print the report as JSON instead of tables}
        {--limit=50 : Rows to show per bucket}';

    protected $description = 'Report customer rows left without a business by the 2026_10_15_000003 backfill';

    /** Bucket order, and the one-line meaning of each. */
    private const BUCKETS = [
        'backfillable' => 'One business, no clash, still NULL — run the migration again',
        'collision' => 'One business, but that business already has this email',
        'multi_business' => 'Orders span more than one business — split by hand',
        'no_orders' => 'No order anywhere names a business — cannot be inferred',
        'no_email' => 'No email on the row at all',
    ];

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $rows = DB::table('customers')
            ->whereNull('business_id')
            ->orderBy('id')
            ->get(['id', 'account_id', 'email', 'first_name', 'last_name', 'status', 'created_at']);

        $report = [];
        $counts = array_fill_keys(array_keys(self::BUCKETS), 0);

        foreach ($rows as $row) {
            $entry = $this->classify($row);
            $counts[$entry['bucket']]++;
            $report[$entry['bucket']][] = $entry;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'total' => $rows->count(),
                'counts' => $counts,
                'buckets' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info("Customer rows with no business: {$rows->count()}");

        if ($rows->isEmpty()) {
            $this->line('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->table(
            ['Bucket', 'Rows', 'What it means'],
            collect(self::BUCKETS)
                ->map(fn (string $meaning, string $bucket) => [$bucket, $counts[$bucket], $meaning])
                ->values()
                ->all(),
        );

        foreach (array_keys(self::BUCKETS) as $bucket) {
            $entries = $report[$bucket] ?? [];

            if ($entries === []) {
                continue;
            }

            $this->line('');
            $this->info("{$bucket} — ".count($entries).' row(s)');

            $this->table(
                ['id', 'account_id', 'email', 'name', 'status', 'orders', 'business(es)', 'clashes with'],
                collect($entries)
                    ->take($limit)
                    ->map(fn (array $entry) => [
                        $entry['id'],
                        $entry['account_id'],
                        $entry['email'] ?: '—',
                        $entry['name'] ?: '—',
                        $entry['status'],
                        $entry['orders'],
                        $entry['businesses'] === [] ? '—' : implode(', ', $entry['businesses']),
                        $entry['conflict'] ?: '—',
                    ])
                    ->all(),
            );

            if (count($entries) > $limit) {
                $this->line('… '.count($entries) - $limit.' more (raise --limit, or use --json)');
            }
        }

        $this->line('');
        $this->comment('Read-only. Nothing was changed; each row above is a decision only you can make.');
        $this->comment('No new order can arrive on these rows — they are unloginable — so this is finite.');

        return self::SUCCESS;
    }

    /**
     * Work out which bucket a row belongs to, and gather what is needed to act.
     *
     * Kept to one pass over orders per row. The volume here is small and
     * bounded — every row is a customer nobody can sign in as — so readability
     * is worth more than a join that would have to survive NULL business ids.
     *
     * @return array{bucket: string, id: int, account_id: string, email: string, name: string, status: string, orders: int, businesses: array<int, string>, conflict: ?string}
     */
    private function classify(object $row): array
    {
        $businessIds = DB::table('orders')
            ->where('customer_id', $row->id)
            ->whereNotNull('business_id')
            ->distinct()
            ->pluck('business_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $orders = DB::table('orders')->where('customer_id', $row->id)->count();

        $entry = [
            'bucket' => 'no_orders',
            'id' => (int) $row->id,
            'account_id' => (string) $row->account_id,
            'email' => (string) ($row->email ?? ''),
            'name' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
            'status' => (string) $row->status,
            'orders' => $orders,
            'businesses' => $this->businessLabels($businessIds),
            'conflict' => null,
        ];

        if ($entry['email'] === '') {
            $entry['bucket'] = 'no_email';

            return $entry;
        }

        if ($businessIds === []) {
            return $entry;
        }

        if (count($businessIds) > 1) {
            $entry['bucket'] = 'multi_business';

            return $entry;
        }

        $businessId = $businessIds[0];

        // MySQL allows repeated NULLs in a unique index, so several orphan rows
        // can share an email. Naming the row that holds `(business_id, email)`
        // is the whole point of this bucket: it is the row this one would
        // collide with, and therefore the one to compare orders against.
        $clash = DB::table('customers')
            ->where('business_id', $businessId)
            ->where('email', $row->email)
            ->where('id', '!=', $row->id)
            ->first(['account_id', 'status']);

        if ($clash) {
            $entry['bucket'] = 'collision';
            $entry['conflict'] = "{$clash->account_id} ({$clash->status})";

            return $entry;
        }

        $entry['bucket'] = 'backfillable';

        return $entry;
    }

    /**
     * Business names for the ids on a row's orders.
     *
     * A name rather than an id: this report is read by a person deciding what
     * to merge, and "Kano Supplies" is what they know the shop as. Falls back
     * to the id so a deleted business still shows up as something.
     *
     * @param  array<int, int>  $businessIds
     * @return array<int, string>
     */
    private function businessLabels(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }

        $names = DB::table('businesses')->whereIn('id', $businessIds)->pluck('name', 'id');

        return array_map(
            fn (int $id) => (string) ($names[$id] ?? "business #{$id}"),
            $businessIds,
        );
    }
}
