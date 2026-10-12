<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\StoreBank;
use App\Services\ActivityRecorder;
use App\Services\Payments\Connect\ConnectConnections;
use App\Services\Payments\Connect\ConnectResult;
use App\Services\Payments\Data\ManagedAccount;
use App\Services\Payments\OnBehalf\Contracts\OnBehalfProcessor;
use App\Services\Payments\OnBehalf\OnBehalfProcessors;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\SystemProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Points each business's account at the processor in force at the bank account
 * the business named.
 *
 * The platform collects one thing from a business: the bank account its sales
 * are paid into. Everything the processor needs is built from that, and the
 * building happens as the business submits it. This command exists for the two
 * ways those two facts come apart afterwards — an admin **changing the
 * processor**, which leaves businesses named at one provider and enforced at
 * another, and a registration whose destination call failed while the account
 * itself was created.
 *
 * ## What it deliberately does not do
 *
 * **It never creates an account.** A provider's entity type and terms
 * acceptance are decided once and are immutable afterwards, and terms are not
 * accepted on anybody's behalf — so a job that provisioned would create an
 * undeletable account from a guessed entity type and a payable destination with
 * no record of consent. Account creation therefore stays where the consent is:
 * the business's own session, in the step that collects the bank account.
 *
 * **It never asks the business for anything.** A business with no account at
 * this processor is reported and skipped; the step it will be shown on its next
 * visit is what closes that, and doing anything else here would be registering
 * somebody else's money behind their back.
 *
 * **It never registers a destination the processor has already declined to make
 * usable.** Whether a destination *works* is the provider's judgement — a
 * restricted account stays restricted however many times its bank is
 * re-registered — so the trigger is a destination that is missing or points
 * somewhere other than the account named on file, never one that is merely
 * unusable. A run against an unusable destination costs a read and writes
 * nothing, which is what keeps this safe to schedule hourly.
 *
 * ## Why it is safe to run repeatedly
 *
 * Every pass reads the stored destination first and compares it with the account
 * on file. A match is nothing to do. The only write is a destination the
 * provider accepted, and it is written by merging over whatever `managed` state
 * was already there, so the owner's terms acceptance survives untouched.
 */
class RegisterConnectPayees extends Command
{
    protected $signature = 'connect:payees
                            {--dry-run : Report what would be registered without sending anything}
                            {--business= : Only this business}';

    protected $description = 'Register each business\'s bank account as its payout destination with the processor in force';

    public function handle(
        ConnectConnections $connections,
        OnBehalfProcessors $processors,
        PlatformBillingGateway $billing,
    ): int {
        $code = SystemProcessor::code();

        if ($code === null) {
            // Two states arrive here and only one of them is a fault. With the
            // switch off no processor is in force, so there is nothing to
            // register a destination *with* — a legitimate no-op, and a
            // scheduled job that failed nightly over it would be noise. With a
            // processor stored that this build cannot run, the platform is dark
            // for every business, and a scheduler reporting success would be the
            // only voice saying otherwise.
            if (SystemProcessor::stored() === null) {
                $this->info('No system payment processor is in force.');

                return self::SUCCESS;
            }

            $this->error('The processor in force cannot be run: '.(SystemProcessor::unavailableReason() ?? 'unknown'));

            return self::FAILURE;
        }

        // Refused up front, and as a failure rather than a quiet no-op: without
        // the platform's credentials nothing can be registered for anyone, and a
        // silent success would read as "every destination is in place".
        if (($problem = $billing->credentialProblem($code)) !== null) {
            Log::error('connect.payees.unavailable', ['provider' => $code, 'reason' => $problem]);
            $this->error('Payout destinations cannot be registered: '.$problem);

            return self::FAILURE;
        }

        $processor = $processors->for($code);

        if ($processor === null) {
            $this->error('The processor in force has no way to process payments on a business\'s behalf in this build.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $rows = [];

        foreach ($this->targets() as $business) {
            $rows[] = $this->register($processor, $connections, $business, $code, $dryRun);
        }

        if ($rows === []) {
            $this->info('No businesses have a bank account named.');

            return self::SUCCESS;
        }

        $this->table(['business', 'account', 'bank', 'result'], $rows);

        $failed = count(array_filter($rows, fn (array $row): bool => $row[4]));

        // Any failure at all is a failure, not just all of them: a destination
        // that could not be registered is a business whose money has no route to
        // its bank, and one of those among a hundred quiet successes is exactly
        // the case worth waking somebody for. (The hourly requirement pass takes
        // the other view, deliberately — a field the provider will not accept is
        // a business's own outstanding chore rather than a fault of the run.)
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The businesses this run is about.
     *
     * Only those that have named a bank account: without one there is nothing to
     * register, and the business is one the step on its next visit is waiting
     * for. Businesses with no account at the enforced processor come back from
     * {@see self::register()} as skipped rather than being filtered out here, so
     * that the report says how many are waiting on setup instead of leaving an
     * operator to infer it from a count that does not add up.
     *
     * @return array<int, Business>
     */
    private function targets(): array
    {
        $businessId = $this->option('business');

        return Business::query()
            ->whereNotNull('payout_bank_id')
            ->when($businessId !== null && $businessId !== '', fn ($query) => $query->whereKey((int) $businessId))
            ->orderBy('id')
            ->get(['id', 'name', 'payout_bank_id'])
            ->all();
    }

    /**
     * One line of the report, and the only place anything is registered.
     *
     * @return array<int, mixed> business, account, bank, result, did it fail
     */
    private function register(
        OnBehalfProcessor $processor,
        ConnectConnections $connections,
        Business $business,
        string $code,
        bool $dryRun,
    ): array {
        $label = $business->name.' (#'.$business->id.')';

        $bank = StoreBank::query()->find($business->payout_bank_id);

        if ($bank === null) {
            // The foreign key nulls the pointer when the account goes, so a
            // missing row is a pointer to something already deleted. Nothing to
            // register, and the business is asked for a bank account again on
            // its next visit — which is the right outcome, not a fault here.
            return [$label, '—', '—', 'no bank account on file', false];
        }

        $connection = $connections->for((int) $business->id, null, $code);
        $accountId = $connection?->gateway_account_id;

        if (! is_string($accountId) || $accountId === '') {
            // The business is named at another processor, or has not been
            // through the step yet. Creating the account is what this command
            // will not do — see the class docblock.
            return [$label, '—', $bank->bank_name, 'no account at this processor yet', false];
        }

        $config = $connections->config($connection);
        $managed = is_array($config['managed'] ?? null) ? $config['managed'] : [];
        $previous = is_array($managed['payout_destination'] ?? null) ? $managed['payout_destination'] : [];

        if ($this->destinationMatches($previous, $bank)) {
            return [$label, $accountId, $bank->bank_name, 'already registered', false];
        }

        if ($dryRun) {
            return [$label, $accountId, $bank->bank_name, 'would register', false];
        }

        $result = $processor->registerDestination($accountId, $this->bankPayload($bank));

        if (! $result->success) {
            Log::warning('connect.payees.destination_failed', [
                'provider' => $code,
                'business_id' => (int) $business->id,
                'account_id' => $accountId,
                'reason' => $result->message,
            ]);

            return [$label, $accountId, $bank->bank_name, $result->message ?? 'failed', true];
        }

        $this->persist($connections, $connection, $config, $result);

        // Recorded because this is a change to where a business's money goes,
        // made by a machine rather than by the business. The owner named the
        // account, so it is not a surprise to them — but the trail is the only
        // place that can answer "why is this business's money landing
        // somewhere else than it was last month".
        ActivityRecorder::record(
            action: 'payment_gateway.payout_destination_registered',
            description: ($business->name).'\'s payout destination re-registered from the bank account on file',
            old: ['last4' => $previous['last4'] ?? null],
            // Never the account number: enough to recognise which account it is
            // and nothing that could be used to send money to it.
            new: [
                'account_id' => $accountId,
                'bank' => $result->destination['bank_name'] ?? $bank->bank_name,
                'last4' => $result->destination['last4'] ?? null,
            ],
            metadata: ['provider' => $code, 'scope' => 'business', 'source' => 'connect:payees'],
            businessId: (int) $business->id,
        );

        Log::info('connect.payees.registered', [
            'provider' => $code,
            'business_id' => (int) $business->id,
            'account_id' => $accountId,
        ]);

        return [$label, $accountId, $bank->bank_name, 'registered', false];
    }

    /**
     * Whether the destination the processor holds is already this bank account.
     *
     * Compared by the last four digits, which is all that is ever stored — the
     * full number goes to the provider and is deliberately not kept, here or
     * anywhere else.
     *
     * **The bank's name is deliberately not part of this.** It is tempting, and
     * it was, and it makes the pass loop forever: providers spell a bank their
     * own way — Paystack's list says "Guaranty Trust Bank" where Bachs' own
     * response says "GTBank" — so a name-sensitive comparison would find them
     * different on every run, register again every hour, and write an audit
     * entry every hour saying a business's money had moved when it had not.
     *
     * Dropping it costs nothing, because of *who* writes this record. A
     * destination is only ever written by the same act that writes the business's
     * pointer: the registration form, and this command repairing a destination
     * that was never written. So the last four digits agreeing means the
     * provider is already paying the account the business has named — and a
     * business that names a *different* account goes through the form, which
     * registers it there and then rather than waiting for this pass.
     */
    private function destinationMatches(mixed $destination, StoreBank $bank): bool
    {
        if (! is_array($destination) || $destination === []) {
            return false;
        }

        $last4 = $destination['last4'] ?? null;

        return is_string($last4)
            && $last4 !== ''
            && str_ends_with((string) $bank->account_number, $last4);
    }

    /**
     * The bank, in the shape every processor's `registerDestination()` takes.
     *
     * Read from the business's own row rather than from anything a caller
     * supplied, so the account number that reaches the provider is the stored
     * one and not a value that happened to be passed in.
     *
     * @return array{account_number: string, account_name: string, bank_code: string, bank_name: string|null}
     */
    private function bankPayload(StoreBank $bank): array
    {
        return [
            'account_number' => (string) $bank->account_number,
            'account_name' => (string) $bank->account_name,
            'bank_code' => (string) $bank->bank_code,
            'bank_name' => $bank->bank_name === null ? null : (string) $bank->bank_name,
        ];
    }

    /**
     * Store what the processor reported, over the state already on the row.
     *
     * A union rather than a replacement: `managed` also holds the owner's terms
     * acceptance, and this run has no business erasing the record of a
     * contractual act — the requirement pass on the next hour reads it back to
     * submit `tos_acceptance`, and dropping it would make a submission we still
     * owe the provider impossible to send.
     *
     * @param  array<string, mixed>  $config  the config as read, before this write
     */
    private function persist(
        ConnectConnections $connections,
        object $connection,
        array $config,
        ConnectResult $result,
    ): void {
        $existing = is_array($config['managed'] ?? null) ? $config['managed'] : [];

        $config['managed'] = ($result->account ?? new ManagedAccount)
            ->withDestination($result->destination)
            ->toConfig() + $existing;

        $connections->writeConfig($connection, $config);
    }
}
