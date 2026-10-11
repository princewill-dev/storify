<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Store;
use App\Services\Payments\Connect\ConnectConnections;
use App\Services\Payments\Connect\ConnectRequirementResult;
use App\Services\Payments\Connect\ConnectRequirementService;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fills in whatever the provider is still asking connected accounts for.
 *
 * Registering a payout account already runs one pass, so a business that
 * supplied everything we need is ready before it finishes reading the success
 * message. This exists for everything after that: the provider adds a field to
 * its requirements, a business completes KYC *after* registering, a document
 * clears review and a new one is demanded, or a submission was refused while the
 * provider was unreachable and has to be tried again.
 *
 * Left running on a schedule rather than triggered only by events because the
 * requirements move on the provider's side. Nothing on our side changes when
 * Bachs decides it now wants a `product_description`, so there is no event of
 * ours to hook.
 *
 * ## Why it is safe to run repeatedly
 *
 * Every pass reads the account first and submits only fields the provider is
 * currently asking for. A field already `pending_verification` is skipped —
 * re-sending it would reset a check that was already under way — and a field
 * whose `resolution` is `review` is never touched. A field that comes back
 * outstanding after a submission is reported rather than retried, so a value the
 * provider rejects cannot become a loop.
 *
 * It never invents anything. {@see ConnectRequirementService} submits from an
 * allowlist of data the business already gave us, and reports the rest in words
 * for the owner to act on.
 */
class SubmitConnectRequirements extends Command
{
    protected $signature = 'connect:requirements
                            {--dry-run : Report what would be submitted without sending anything}
                            {--account= : Only this connected account}
                            {--business= : Only this business\'s accounts}';

    protected $description = 'Submit the details connected account providers have asked us for';

    public function handle(
        ConnectConnections $connections,
        ConnectRequirementService $requirements,
        PlatformBillingGateway $billing,
    ): int {
        // Refused up front, and as a failure rather than a quiet no-op: without
        // the platform's key nothing can be read or submitted for anyone, and a
        // silent success would look like "all accounts are satisfied".
        if (($problem = $billing->credentialProblem('bachs')) !== null) {
            Log::error('bachs.connect.requirements_unavailable', ['reason' => $problem]);
            $this->error('Connected account requirements cannot be submitted: '.$problem);

            return self::FAILURE;
        }

        $account = $this->option('account');
        $businessId = $this->option('business');

        $rows = [];

        foreach ($this->targets($connections, $account, $businessId) as $connection) {
            $target = $connections->scopeOf($connection);
            $business = Business::query()->find($target['business_id']);

            if ($business === null) {
                continue;
            }

            $store = $target['store_id'] === null
                ? null
                : Store::query()->find($target['store_id']);

            $result = $requirements->submit(
                (string) $connection->gateway_account_id,
                $business,
                $store,
                (bool) $this->option('dry-run'),
            );

            $rows[] = $this->row($connection->gateway_account_id, $target, $result);
        }

        if ($rows === []) {
            $this->info('No connected accounts matched.');

            return self::SUCCESS;
        }

        $this->table(['account', 'business', 'store', 'submitted', 'still needed'], $rows);

        $failed = count(array_filter($rows, fn (array $row): bool => $row[5]));

        return $failed > 0 && $failed === count($rows) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The connections this run is about.
     *
     * Filtered here rather than by a query per option so that `--account` and
     * `--business` compose, and so a filter that matches nothing reports that
     * rather than silently doing nothing.
     *
     * @return array<int, object>
     */
    private function targets(ConnectConnections $connections, ?string $account, ?string $businessId): array
    {
        $rows = $connections->all();

        if ($account !== null && $account !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (object $row): bool => $row->gateway_account_id === $account,
            ));
        }

        if ($businessId !== null && $businessId !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (object $row): bool => (int) $row->business_id === (int) $businessId,
            ));
        }

        return $rows;
    }

    /**
     * One line of the report.
     *
     * The counts rather than the field names: the names are logged by the
     * service, and a console table wide enough to hold a dozen provider field
     * paths is a console table nobody reads. What an operator needs from this is
     * *which account still needs something*.
     *
     * @param  array{business_id: int, store_id: int|null}  $target
     * @return array<int, mixed>
     */
    private function row(string $accountId, array $target, ConnectRequirementResult $result): array
    {
        if (! $result->success) {
            return [$accountId, $target['business_id'], $target['store_id'] ?? '—', '—', $result->message ?? 'failed', true];
        }

        $still = count($result->unresolvable) + count($result->waiting);

        return [
            $accountId,
            $target['business_id'],
            $target['store_id'] ?? '—',
            count($result->submitted),
            match (true) {
                $result->unresolvable !== [] => $still.' ('.count($result->unresolvable).' we cannot fill)',
                $still > 0 => (string) $still,
                default => 'nothing',
            },
            false,
        ];
    }
}
