<?php

namespace App\Services\Payments\Connect;

use App\Models\Business;
use App\Models\KycApplication;
use App\Models\Store;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\ManagedAccount;
use App\Services\Payments\Gateways\BachsApi;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Support\Facades\Log;

/**
 * Filling in a connected account's outstanding requirements from what we know.
 *
 * The provider asks an account for fields — a registered name, a support email,
 * a date of birth — and marks each one with a `resolution` saying who can
 * supply it. `review` means the paperwork is already with Bachs and re-sending
 * it achieves nothing. `api` means it is ours to submit, and this is what
 * submits it: the business already told us these things when it registered and
 * when it completed KYC, and asking a shopkeeper to retype their own legal name
 * into a provider's form is the friction the platform exists to remove.
 *
 * ## What it will and will not send
 *
 * **Only what we hold, and only from an allowlist.** Every field is matched
 * against a named source — `businesses`, `users`, the owner's `kyc_applications`
 * — rather than guessed from a partial name match. A requirement this does not
 * recognise is reported with a sentence saying so; it is never filled with the
 * nearest plausible value, because the provider treats these as identity and
 * verification data and a wrong answer is worse than an absent one.
 *
 * **A person's details only when there is one person.** Our KYC application is
 * the business owner's. The provider's account can hold several people, and
 * there is nothing in a requirement key like `persons.per_…` to say which of
 * ours corresponds to which of theirs — so if more than one appears, person
 * fields are reported rather than submitted. Guessing would attach one
 * person's identity documents to another person's record.
 *
 * **Terms are not accepted on anybody's behalf.** `tos_acceptance` is a
 * contractual act by the business, so its values come from an acceptance the
 * owner actually gave on our own form (see `terms_accepted` on the payout
 * account request). Nothing here invents one.
 *
 * ## Idempotence
 *
 * Every pass starts from a fresh read of the account and submits only fields the
 * provider is currently asking for. A field already in `pending_verification` is
 * left alone; a field that comes back outstanding after a submission is reported
 * rather than retried, so a value the provider rejects cannot become a loop.
 */
final class ConnectRequirementService
{
    /** The prefix under which the provider keys per-person requirements. */
    private const PERSON_PREFIX = 'persons.';

    public function __construct(
        private readonly PlatformBillingGateway $platform,
        private readonly BachsConnectAccountService $accounts,
        private readonly ConnectConnections $connections,
    ) {}

    /**
     * One pass over an account's outstanding requirements.
     *
     * @param  ManagedAccount|null  $account  a read already in hand, to save the extra call
     */
    public function submit(string $accountId, Business $business, ?Store $store = null, bool $dryRun = false, ?ManagedAccount $account = null): ConnectRequirementResult
    {
        $account ??= $this->accounts->refresh($accountId);

        if ($account === null) {
            return ConnectRequirementResult::failed('Bachs could not be read just now, so nothing was submitted.');
        }

        $values = $this->valuesFor($business, $store, $accountId);
        $submitted = [];
        $unresolvable = [];
        $waiting = [];
        $withProvider = [];

        $fields = [];
        $persons = [];
        $personIds = $this->personIds($account);

        foreach ($account->requirements as $requirement) {
            $field = $requirement['field'] ?? null;

            if (! is_string($field) || $field === '') {
                continue;
            }

            $status = (string) ($requirement['status'] ?? '');
            $resolution = $requirement['resolution'] ?? null;

            // Already with the provider being checked. Sending it again is at
            // best ignored and at worst resets the check, so it is neither
            // submitted nor reported as something anyone can act on.
            if ($status === 'pending_verification') {
                $waiting[] = $field;

                continue;
            }

            // `review`, and anything we do not recognise: not ours to resolve.
            // An unrecognised resolution is treated the same way deliberately —
            // the provider documents only the two, and a third would mean a
            // mechanism we do not know how to satisfy.
            if ($resolution !== 'api') {
                $withProvider[] = $field;

                continue;
            }

            // A person requirement, when we cannot tell which of the provider's
            // people our own records describe.
            if (str_starts_with($field, self::PERSON_PREFIX)) {
                if (count($personIds) !== 1) {
                    $unresolvable[$field] = count($personIds) > 1
                        ? 'This account has more than one person, so we cannot tell which one this belongs to.'
                        : 'No person is on this account yet.';

                    continue;
                }

                $key = $this->personKey($field, $personIds[0]);
                $value = $key === null ? null : ($values['person.'.$key] ?? null);

                if ($value === null) {
                    $unresolvable[$field] = $this->whyNot($key === null ? $field : 'person.'.$key);

                    continue;
                }

                $persons[$personIds[0]][$key] = $value;
                $submitted[] = $field;

                continue;
            }

            $value = $values[$field] ?? null;

            if ($value === null) {
                $unresolvable[$field] = $this->whyNot($field);

                continue;
            }

            $fields[$field] = $value;
            $submitted[] = $field;
        }

        if ($dryRun || ($fields === [] && $persons === [])) {
            return ConnectRequirementResult::done($submitted, $unresolvable, $waiting, $withProvider, $account, $dryRun);
        }

        $credentials = $this->platform->credentialsFor('bachs');

        if ($fields !== [] && ($problem = $this->postAccountFields($accountId, $fields, $credentials)) !== null) {
            return ConnectRequirementResult::failed($problem);
        }

        foreach ($persons as $personId => $personFields) {
            if (($problem = $this->postPersonFields($accountId, $personId, $personFields, $credentials)) !== null) {
                return ConnectRequirementResult::failed($problem);
            }
        }

        Log::info('bachs.connect.requirements_submitted', [
            'account_id' => $accountId,
            'business_id' => $business->id,
            // Field names only. These are identity values and the log is not
            // the place for them.
            'fields' => array_keys($fields),
            'persons' => array_map('array_keys', $persons),
            'unresolvable' => array_keys($unresolvable),
        ]);

        // Re-read rather than assume: the provider can accept a value and still
        // keep the requirement open while it verifies what was sent, and a
        // screen told the requirement is gone would be a screen that stops
        // asking for the thing it still wants.
        return ConnectRequirementResult::done(
            $submitted,
            $unresolvable,
            $waiting,
            $withProvider,
            $this->accounts->refresh($accountId) ?? $account,
        );
    }

    /**
     * `POST /v1/accounts/{id}` with a `fields` map.
     *
     * One call for every account-level field rather than one per field: the
     * endpoint applies the whole map or rejects all of it, so a partial success
     * is not a state it can produce.
     *
     * @param  array<string, mixed>  $fields
     */
    private function postAccountFields(string $accountId, array $fields, GatewayCredentials $credentials): ?string
    {
        return $this->post(
            BachsApi::baseUrl($credentials).'/accounts/'.rawurlencode($accountId),
            ['fields' => $fields],
            $credentials,
            $accountId,
        );
    }

    /**
     * `POST /v1/accounts/{id}/persons/{person_id}` for fields that belong to a
     * person rather than to the account.
     *
     * @param  array<string, mixed>  $fields
     */
    private function postPersonFields(string $accountId, string $personId, array $fields, GatewayCredentials $credentials): ?string
    {
        return $this->post(
            BachsApi::baseUrl($credentials).'/accounts/'.rawurlencode($accountId).'/persons/'.rawurlencode($personId),
            $fields,
            $credentials,
            $accountId,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $url, array $body, GatewayCredentials $credentials, string $accountId): ?string
    {
        try {
            $response = BachsApi::client($credentials, $accountId)->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('bachs.connect.requirement_request_failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return 'Bachs could not be reached, so some details were not submitted. They will be sent again shortly.';
        }

        if ($response->successful()) {
            return null;
        }

        $payload = $response->json() ?? [];
        // The provider names the offending fields on a 400. They are logged but
        // never echoed to a business owner, who cannot act on a field path.
        Log::warning('bachs.connect.requirement_refused', [
            'account_id' => $accountId,
            'status' => $response->status(),
            'code' => $payload['code'] ?? null,
            'fields' => is_array($payload['errors'] ?? null) ? array_keys($payload['errors']) : null,
        ]);

        if ($response->status() === 400 && ($payload['code'] ?? null) === 'INVALID_REQUIREMENT_FIELD') {
            return 'Bachs rejected some of the details we submitted. Support has been notified.';
        }

        return 'Bachs did not accept the details we submitted just now. They will be sent again shortly.';
    }

    /**
     * Everything we could submit, keyed by the provider's field path.
     *
     * A flat map, built once, so classification is a lookup rather than a chain
     * of conditionals — and so that adding a field is adding a line here rather
     * than a branch a reader has to follow. `null` values are simply absent
     * mappings: what is missing from this array is exactly what the report calls
     * unresolvable, which is why nothing here should ever be filled with a
     * plausible-looking default.
     *
     * @return array<string, mixed>
     */
    private function valuesFor(Business $business, ?Store $store, string $accountId): array
    {
        $owner = $business->owner;
        $kyc = $this->kycFor($business);
        $legalName = $kyc?->legal_name;

        $values = [
            // The business's published contact points, and the owner's as the
            // fallback — the provider requires an email, and a business row with
            // none is common.
            'business_profile.support_email' => $this->first($store?->support_email, $owner?->email),
            'business_profile.support_phone' => $this->first($store?->support_phone, $kyc?->phone_number, $owner?->phone),
            'support_phone' => $this->first($store?->support_phone, $kyc?->phone_number, $owner?->phone),
            'business_profile.url' => $this->hostedUrl($store),

            // What the business sells, in the business's own words. Absent
            // rather than invented when the description was never filled in.
            'business_profile.product_description' => $this->first($business->description),

            // Verified identity, from the KYC application the owner submitted
            // to us — the same answers, so the same trust.
            'company.registered_name' => $this->first($legalName, $business->name),
            'company.registration_number' => $this->registrationNumber($kyc),

            'person.first_name' => $this->firstName($legalName, $owner?->name),
            'person.last_name' => $this->lastName($legalName, $owner?->name),
            'person.name' => $this->first($legalName, $owner?->name),
            'person.email' => $this->first($owner?->email),
            'person.phone' => $this->first($kyc?->phone_number, $owner?->phone),
            'person.dob' => $kyc?->date_of_birth?->format('Y-m-d'),
            'person.id_number' => $this->idNumber($kyc),
        ];

        // Countries are two-letter ISO codes at the provider and a free-text
        // name in our KYC form, so only an already-ISO value is passed through.
        $address = array_filter([
            'line1' => $this->first($kyc?->address_line),
            'city' => $this->first($kyc?->city),
            'state' => $this->first($kyc?->state),
            'country' => $this->isoCountry($kyc?->country),
        ], fn ($value) => $value !== null);

        if ($address !== []) {
            $values['person.address'] = $address;
        }

        foreach ($this->termsAcceptance($accountId) as $key => $value) {
            $values['tos_acceptance.'.$key] = $value;
        }

        return array_filter($values, fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * The terms acceptance the owner gave on our form, if any.
     *
     * Read from the connection row rather than taken from the request, because
     * this is submitted long after the form was filled in — by a sweep, or by a
     * capability update at three in the morning. No acceptance on record means
     * no terms submitted, and the requirement stays outstanding where a person
     * can see it.
     *
     * **The date is translated, not passed through.** We store ISO 8601 because
     * that is what a person reads in the database and what the API returns to
     * our own screen; the provider's field is a Unix timestamp, the convention
     * this field name carries everywhere it appears. Sending the stored string
     * would be a `400` for a formatting difference, which is a thing to get
     * right once here rather than to debug per business. The stored ISO is left
     * alone, so the record of when it was accepted stays readable.
     *
     * @return array<string, mixed>
     */
    private function termsAcceptance(string $accountId): array
    {
        $connection = $this->connections->forAccount($accountId);

        if ($connection === null) {
            return [];
        }

        $managed = $this->connections->config($connection)['managed'] ?? [];
        $managed = is_array($managed) ? $managed : [];

        $acceptedAt = $managed['tos_accepted_at'] ?? null;

        if (! is_string($acceptedAt) || $acceptedAt === '') {
            return [];
        }

        $timestamp = strtotime($acceptedAt);

        if ($timestamp === false) {
            // Unreadable means we cannot honestly say when it was accepted, and
            // a wrong date on a terms acceptance is worse than none: the
            // requirement stays visible for a person to deal with.
            return [];
        }

        return array_filter([
            'date' => $timestamp,
            'ip' => $this->first($managed['tos_accepted_ip'] ?? null),
            'user_agent' => $this->first($managed['tos_accepted_user_agent'] ?? null),
        ], fn ($value) => $value !== null);
    }

    /**
     * The person ids a set of requirements names.
     *
     * @return array<int, string>
     */
    private function personIds(ManagedAccount $account): array
    {
        $ids = [];

        foreach ($account->requirements as $requirement) {
            $field = (string) ($requirement['field'] ?? '');

            if (preg_match('/^persons\.([A-Za-z0-9_]+)\./', $field, $matches) === 1) {
                $ids[$matches[1]] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * `persons.per_abc.first_name` => `first_name`, or null when the path names
     * a person other than the one given.
     *
     * The name is stripped back to the field on the person, because that is what
     * the person endpoint takes: the provider spells a *requirement* on a person
     * as `persons.{id}.first_name` and the field in the body as `first_name`.
     * Our own account-level key for the same value is `person.first_name`, and
     * the caller adds that prefix back for the lookup into `valuesFor()`.
     */
    private function personKey(string $field, string $personId): ?string
    {
        $prefix = self::PERSON_PREFIX.$personId.'.';

        if (! str_starts_with($field, $prefix)) {
            return null;
        }

        $rest = substr($field, strlen($prefix));

        return $rest === '' ? null : $rest;
    }

    /**
     * Why a requirement could not be filled, in words for the owner.
     *
     * Specific where the fix is specific — a registration number we were never
     * given is a different job from a document that has to be uploaded — and
     * honest where we simply do not have a mapping. The field's own name is
     * included so the sentence identifies which requirement it is about, since a
     * report lists several and "we have nothing on file" alone would not say for
     * which.
     */
    private function whyNot(string $field): string
    {
        $label = ConnectRequirementLabels::label($field);
        $note = ConnectRequirementLabels::note($field);

        return $note === null
            ? $label.': we have nothing on file for this requirement.'
            : $label.': '.lcfirst($note);
    }

    private function kycFor(Business $business): ?KycApplication
    {
        $owner = $business->owner;

        return $owner?->kycApplication;
    }

    /**
     * The business's own storefront, which is the only website we can be sure is
     * theirs — a business row has no URL column, and a slug is not a domain.
     */
    private function hostedUrl(?Store $store): ?string
    {
        $slug = $store?->slug;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $domain = (string) config('frontend.storefront_main_domain', '');

        return $domain === '' ? null : 'https://'.$slug.'.'.$domain;
    }

    /**
     * A registration number, from the KYC payload's own keys.
     *
     * Two spellings because the form's field has been called both at different
     * times, and reading only one would silently stop finding it.
     */
    private function registrationNumber(?KycApplication $kyc): ?string
    {
        $payload = is_array($kyc?->payload) ? $kyc->payload : [];

        return $this->first(
            $payload['registration_number'] ?? null,
            $payload['rc_number'] ?? null,
            $payload['company_registration_number'] ?? null,
        );
    }

    private function idNumber(?KycApplication $kyc): ?string
    {
        $payload = is_array($kyc?->payload) ? $kyc->payload : [];

        return $this->first(
            $payload['id_number'] ?? null,
            $payload['nin'] ?? null,
            $payload['bvn'] ?? null,
        );
    }

    /**
     * Only an already-ISO code is passed on.
     *
     * The provider wants ISO 3166-1 alpha-2 and our KYC form collects whatever
     * the owner typed. "Nigeria" sent as a country code is a rejection the owner
     * cannot see or fix, so a non-ISO value is reported as unresolvable instead.
     */
    private function isoCountry(?string $country): ?string
    {
        $country = trim((string) $country);

        return preg_match('/^[A-Za-z]{2}$/', $country) === 1 ? strtoupper($country) : null;
    }

    /**
     * Split a full name into the two parts the provider asks for separately.
     *
     * First word and everything after it: wrong for many names, and the reason
     * the provider asks for the parts separately in the first place. It is still
     * the right default here — the alternative is sending a single `name` field
     * the provider did not ask for, or nothing at all.
     */
    private function firstName(?string $legalName, ?string $fallback): ?string
    {
        $name = trim((string) ($legalName ?? $fallback));

        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        return $parts[0] ?? null;
    }

    private function lastName(?string $legalName, ?string $fallback): ?string
    {
        $name = trim((string) ($legalName ?? $fallback));

        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
    }

    /**
     * The first non-empty string of the candidates, or null.
     */
    private function first(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
