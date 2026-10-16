<?php

namespace App\Services\Payments\Payouts;

use App\Models\PayoutBankCode;
use App\Models\StoreBank;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Turns a business's stored bank account into every payout provider's own terms.
 *
 * ## What a business actually names
 *
 * One bank, once, out of the picker — and the picker is Paystack's list, so what
 * lands in `store_banks` is a Paystack code and a Paystack spelling of the bank's
 * name. Payouts then leave through whichever provider is the platform default,
 * which is a different list with different codes:
 *
 *     Opay          Paystack 999992   Bachs 305
 *     PalmPay       Paystack 999991   Bachs 100033
 *     Zenith        Paystack 057      Bachs 057
 *
 * So "the business's bank" is not one fact. It is one fact *per provider*, and this
 * is what works them out.
 *
 * ## Why the work happens at setup and not at the send
 *
 * Because the answer can be *no*, and a payout is the worst place to discover it.
 * Bachs lists 286 banks and Paystack 290, overlapping by about 133; a business
 * banking with a microfinance bank that only one of them carries cannot be paid
 * through the other at all. Bachs answers a code it does not recognise with
 * `400 BANK_NOT_PAYABLE`, which the sweep files as `destination_unusable` — so
 * after three attempts the account is exhausted, the admin alert blames the
 * provider, and re-adding the account reproduces it exactly. Nothing named the real
 * cause, because nothing knew it.
 *
 * Asked at setup, the same fact is a sentence the business reads and acts on.
 *
 * ## Registration is part of resolving, not a step after it
 *
 * A resolvable bank code is not yet a payable account: the provider still has to
 * accept the account number. So the destination is registered while we are here
 * and the id is kept, which is also the only thing that keeps registration down to
 * **once per account** instead of once per payout. Bachs does not deduplicate
 * registrations — three identical ones returned three different ids — so every
 * payout used to create a fresh destination, without bound.
 */
final class PayoutBankResolver
{
    public function __construct(
        private readonly BankDirectory $directory,
        private readonly PlatformBillingGateway $billing,
    ) {}

    /**
     * Work out how every payout provider sees this account.
     *
     * **Writes nothing.** The setup step needs the answer before it commits the
     * account, so that a refusal stops the write rather than arriving after it —
     * and until it commits there is no row for an answer to be stored against.
     *
     * Returns one entry per rail in the registry, keyed by provider code. Never
     * empty and never throws: a provider that cannot pay this bank gets an entry
     * carrying the reason, which is the point — the answer has to survive to be
     * shown.
     *
     * `$bank` may be unsaved. Only its bank name, code, account number and account
     * name are read.
     *
     * @return array<string, PayoutBankResolution>
     */
    public function resolveAll(StoreBank $bank, bool $register = true): array
    {
        $resolutions = [];

        foreach (PayoutRailRegistry::codes() as $provider) {
            $resolutions[$provider] = $this->resolve($bank, $provider, $register);
        }

        return $resolutions;
    }

    /**
     * Work out how one provider sees this account and register the destination.
     *
     * The two questions are asked in order and the failures are kept apart, because
     * they are different things to tell a person:
     *
     * - **the bank is not in this provider's list** — unpayable for every business
     *   on the platform, and nothing the business can do about it;
     * - **the provider refused this account** — unpayable for this business, and
     *   usually something they can fix.
     *
     * Both are answered `null` by {@see BankDirectory::codeFor()}, which is why the
     * list is checked for readability first: a third case — "we could not ask",
     * because the provider's list came back as an error or as nothing at all — is
     * `unchecked()` instead of a refusal, and is recorded and retried rather than
     * shown to a business. Every bank would be unpayable at a provider whose list
     * we failed to read, which is not a thing that can be true.
     *
     * `$register = false` stops before the provider is asked to create anything and
     * returns the code that was worked out. That is what a dry run uses: the
     * question it reports on is "can this provider pay this bank", and a reporting
     * pass that registers destinations is not a reporting pass.
     */
    public function resolve(StoreBank $bank, string $provider, bool $register = true): PayoutBankResolution
    {
        $rail = PayoutRailRegistry::make($provider);
        $name = $this->providerName($provider);

        // Credentials first, because the bank list is read *with* them and there is
        // no such thing as asking a provider anonymously. A provider with none
        // configured has not said no to anything.
        $credentials = $this->credentials($provider);

        if ($credentials === null) {
            return PayoutBankResolution::unchecked(
                $provider,
                $this->billing->credentialProblem($provider) ?? 'Storify has no platform credentials for this provider.',
            );
        }

        // Then whether the list could be read at all, because "this bank is not in
        // the list" and "we could not read the list" both come back as a null code
        // and only one of them is a thing to tell a business.
        if (! $this->directory->readable($rail, $credentials)) {
            return PayoutBankResolution::unchecked(
                $provider,
                sprintf('We could not reach %s to check this bank. It will be checked again automatically.', $name),
            );
        }

        $code = $this->directory->codeFor($rail, $credentials, (string) $bank->bank_code, (string) $bank->bank_name);

        if ($code === null) {
            // Worded as "cannot pay into" rather than "does not list", because a
            // null code covers two cases — the bank is absent from the list, and
            // the bank is present under a name we refused to match because more
            // than one entry was plausible. The first sentence is true of both;
            // "does not list" would be a lie in the second, and it is precisely
            // the kind of confident wrong answer this whole mechanism exists to
            // stop producing.
            return PayoutBankResolution::refused(
                $provider,
                null,
                sprintf('%s cannot pay into an account at %s. Try another bank account, or contact support.', $name, $bank->bank_name),
            );
        }

        // The dry run's exit: the code is worked out and the provider is not asked
        // to create anything.
        if (! $register) {
            return PayoutBankResolution::unregistered($provider, $code);
        }

        try {
            $destination = $rail->ensureDestination(
                $credentials,
                (string) $bank->account_number,
                $code,
                $bank->account_name,
                (string) config('custody.currency', 'NGN'),
            );
        } catch (\Throwable $e) {
            Log::warning('payout.bank_resolver.registration_threw', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return PayoutBankResolution::unchecked(
                $provider,
                'The provider could not be reached to register this account. It will be tried again automatically.',
            );
        }

        if (! $destination->payable()) {
            $reason = $destination->message ?? 'The provider will not accept this account for payouts.';

            // The rail says which of the two it was. A refusal is the provider's
            // verdict on the account and stops the setup; an indeterminate one is
            // a dropped connection and only postpones it.
            return $destination->indeterminate
                ? PayoutBankResolution::unchecked($provider, $reason)
                : PayoutBankResolution::refused($provider, $code, $reason);
        }

        return new PayoutBankResolution(
            provider: $provider,
            bankCode: $code,
            destinationId: $destination->id,
            usable: $destination->usable,
        );
    }

    /**
     * Record resolutions against a stored account.
     *
     * @param  array<string, PayoutBankResolution>  $resolutions
     */
    public function persist(StoreBank $bank, array $resolutions): void
    {
        foreach ($resolutions as $provider => $resolution) {
            $resolution->store($bank);

            if (! $resolution->payable()) {
                // Two levels, because they are two different jobs. A refusal is
                // permanent and somebody will have to act on it, a business
                // included; an unchecked account is our own outage and clears
                // itself on the next pass.
                Log::log(
                    $resolution->conclusive ? 'warning' : 'info',
                    $resolution->conclusive ? 'payout.bank_resolver.refused' : 'payout.bank_resolver.unchecked',
                    [
                        'store_bank_id' => $bank->id,
                        'provider' => $provider,
                        'reason' => $resolution->reason,
                    ],
                );

                continue;
            }

            Log::info('payout.bank_resolver.resolved', [
                'store_bank_id' => $bank->id,
                'provider' => $provider,
                'bank_code' => $resolution->bankCode,
                'destination_id' => $resolution->destinationId,
                // Whether the two code spaces disagreed. A shared code is the
                // quiet case; the fintechs are the loud one, and this is the line
                // that says so.
                'translated' => $resolution->translated($bank),
            ]);
        }
    }

    /**
     * Resolve and record in one step, for a stored account.
     *
     * The convenience the backfill and the operator's repair path use. The setup
     * path deliberately does not: it needs the gap between the two.
     *
     * @return array<string, PayoutBankResolution>
     */
    public function resolveAndPersist(StoreBank $bank): array
    {
        $resolutions = $this->resolveAll($bank);

        $this->persist($bank, $resolutions);

        return $resolutions;
    }

    /**
     * The recorded answer for this account at this provider, without asking anyone.
     *
     * What the dispatch path reads. Null means nobody has worked it out yet — an
     * account named before any of this existed — and the caller resolves on the
     * spot rather than refusing, because a business holding money must not be
     * stopped by our own migration backlog.
     */
    public function stored(StoreBank $bank, string $provider): ?PayoutBankCode
    {
        return PayoutBankCode::query()
            ->where('store_bank_id', $bank->id)
            ->where('provider', $provider)
            ->first();
    }

    /**
     * The provider a payout would leave through if one were sent right now.
     *
     * Read at the moment of asking, and that is the point of the whole design: the
     * admin can switch processors without telling anybody, so a business's bank
     * being payable is not a fact that can be settled once. Null when no processor
     * is selected or the build cannot run the selected one — in which case nothing
     * pays out at all and there is nothing to refuse a business over.
     */
    public function defaultProvider(): ?string
    {
        $selected = SystemProcessor::code();

        return $selected !== null && PayoutRailRegistry::has($selected) ? $selected : null;
    }

    /**
     * Refuse the setup when the provider that would actually pay this business
     * cannot.
     *
     * **Only the default provider can refuse, and only conclusively.** The other
     * rails' answers are recorded and ignored, because a business is not required
     * to care which processor Storify uses or what the one it is not using thinks —
     * a bank that Bachs cannot pay is no reason to stop a business whose payouts
     * would go through Paystack. And a provider we could not reach is not an
     * answer at all: refusing on that would mean one provider's outage stops every
     * business on the platform from naming a bank account.
     *
     * @param  array<string, PayoutBankResolution>  $resolutions
     *
     * @throws ValidationException when the default provider has said no
     */
    public function guardDefault(StoreBank $bank, array $resolutions): void
    {
        $default = $this->defaultProvider();

        if ($default === null) {
            return;
        }

        $resolution = $resolutions[$default] ?? null;

        if ($resolution === null || ! $resolution->blocksSetup()) {
            return;
        }

        Log::info('payout.bank_resolver.setup_refused', [
            'store_bank_id' => $bank->id,
            'provider' => $default,
            'bank_name' => $bank->bank_name,
        ]);

        throw ValidationException::withMessages([
            // Keyed on `bank_code` because that is the field the add-account form
            // sends the chosen bank in, so the message lands on the control that
            // has to change.
            'bank_code' => [$resolution->reason ?? 'Storify cannot pay into this bank account.'],
        ]);
    }

    /**
     * Refuse the setup when the provider that would pay this business has already
     * refused this account — reading the answer we hold rather than asking again.
     *
     * The second caller of {@see self::guardDefault()}'s rule, and the reason it is
     * a separate method is the same as the reason the answer is stored at all: a
     * registration is **once per account per provider**, and Bachs does not
     * deduplicate. Three identical registrations returned three different
     * destination ids, so re-resolving on every repoint would put a fresh
     * destination at the provider each time a business changed which of its own
     * accounts pays out — the accumulation storing the id exists to stop.
     *
     * It also keeps a settings action off the provider's availability. The verdict
     * that matters here is the one already on file; asking again would let a Bachs
     * outage block a business from choosing its own account, and would tell it
     * nothing it is not already recorded as having been told.
     *
     * An account nobody has resolved yet is not refused: that is a gap in our own
     * records, filled by the backfill and by the send path resolving on the spot,
     * and it is not an answer to turn a business away with.
     *
     * @throws ValidationException when the default provider has refused this account
     */
    public function guardStored(StoreBank $bank): void
    {
        $default = $this->defaultProvider();

        if ($default === null) {
            return;
        }

        $stored = $this->stored($bank, $default);

        if ($stored === null || ! $stored->refused()) {
            return;
        }

        Log::info('payout.bank_resolver.repoint_refused', [
            'store_bank_id' => $bank->id,
            'provider' => $default,
            'bank_name' => $bank->bank_name,
        ]);

        throw ValidationException::withMessages([
            'store_bank_id' => [
                $stored->failure_reason
                    ?? sprintf('%s cannot pay into this bank account. Choose another account, or contact support.', $this->providerName($default)),
            ],
        ]);
    }

    /**
     * The platform's credentials for a provider, or null when it has none.
     *
     * Null rather than an empty credential set, which would be sent and answered
     * with a 401 — an error that reads like our key is wrong when the truth is that
     * this provider was never configured.
     */
    private function credentials(string $provider): ?GatewayCredentials
    {
        if ($this->billing->credentialProblem($provider) !== null) {
            return null;
        }

        return $this->billing->credentialsFor($provider);
    }

    /**
     * What to call the provider in a sentence a business will read.
     *
     * From the gateway registry rather than uppercased from the code, so the name
     * is the one on the payment settings screen.
     */
    private function providerName(string $provider): string
    {
        return (string) (PaymentGatewayRegistry::get($provider)['name'] ?? $provider);
    }
}
