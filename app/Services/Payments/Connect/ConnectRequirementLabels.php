<?php

namespace App\Services\Payments\Connect;

/**
 * What a provider requirement field is called in English, and who can answer it.
 *
 * The provider names its requirements as field paths — `company.registration_number`,
 * `persons.per_3a91c0d7.id_document` — which is exactly right for an API and
 * useless on a screen. A business owner shown "Add the bank account your sales
 * are paid into" beside "persons.per_3a91c0d7.id_document" has been told half a
 * sentence and a database key.
 *
 * One table, read by two callers: the management screen, to label each
 * outstanding requirement, and {@see ConnectRequirementService}, to say why it
 * cannot fill one in from data we hold. Two tables would drift, and the drift
 * would show as a screen asking for one thing and an automated submission
 * attempting another.
 *
 * A field that is not here is not broken. New fields are expected — the provider
 * adds them per country and entity type — so an unknown one is titled by its own
 * last segment rather than shown raw, which reads acceptably without anyone
 * having to keep this list complete.
 */
final class ConnectRequirementLabels
{
    /**
     * Field path => the words a business owner reads.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'business_profile.support_email' => 'A support email address',
        'business_profile.support_phone' => 'A support phone number',
        'business_profile.url' => 'Your website address',
        'business_profile.product_description' => 'A description of what you sell',
        'business_profile.product_category' => 'A product category',
        'business_profile.social_media' => 'A social media profile',

        'company.registered_name' => 'Your registered company name',
        'company.registration_number' => 'Your company registration number',
        'company.structure' => 'Your company structure',
        'company.documents.registration' => 'Your certificate of registration',
        'company.documents.memart' => 'Your memorandum and articles of association',

        'person.name' => 'The owner\'s full name',
        'person.first_name' => 'The owner\'s first name',
        'person.last_name' => 'The owner\'s last name',
        'person.dob' => 'The owner\'s date of birth',
        'person.email' => 'The owner\'s email address',
        'person.phone' => 'The owner\'s phone number',
        'person.address' => 'The owner\'s home address',
        'person.id_number' => 'The owner\'s ID number',
        'person.id_document' => 'The owner\'s identity document',
        'person.proof_of_address' => 'A proof of address for the owner',
        'person.bvn' => 'The owner\'s BVN',

        // All three name one act. The provider models the acceptance as several
        // requirements and a list repeating "Acceptance of Bachs' terms" three
        // times beside itself reads as three jobs rather than one.
        'tos_acceptance.date' => 'Acceptance of Bachs\' terms',
        'tos_acceptance.ip' => 'Acceptance of Bachs\' terms',
        'tos_acceptance.user_agent' => 'Acceptance of Bachs\' terms',
        'tos_acceptance.service_agreement' => 'Acceptance of Bachs\' terms',
    ];

    /**
     * A requirement field in words.
     */
    public static function label(string $field): string
    {
        $normalised = self::normalise($field);

        if (isset(self::LABELS[$normalised])) {
            return self::LABELS[$normalised];
        }

        // "persons.per_abc.id_document" has already become "person.id_document";
        // anything still unknown is titled by its last segment and spaced out,
        // which is legible enough to act on until somebody names it properly.
        $segment = substr((string) strrchr('.'.$normalised, '.'), 1);

        return ucfirst(str_replace(['_', '.'], ' ', $segment === '' ? $normalised : $segment));
    }

    /**
     * Why we cannot submit this one ourselves, or null when we can.
     *
     * Only the fields that genuinely cannot come from data we hold. Anything not
     * listed is either submittable or simply not ours to answer, and the caller
     * distinguishes those itself.
     */
    public static function note(string $field): ?string
    {
        $normalised = self::normalise($field);

        return match (true) {
            str_ends_with($normalised, '.id_document'),
            str_ends_with($normalised, '.documents.registration'),
            str_ends_with($normalised, '.documents.memart') => 'A document has to be uploaded for this, so it cannot be filled in from your details.',

            $normalised === 'business_profile.product_category' => 'This has to be chosen from Bachs\' own list of categories, so we cannot fill it in from your details.',
            $normalised === 'company.structure' => 'This has to be chosen from Bachs\' own list, so we cannot fill it in from your details.',
            $normalised === 'business_profile.social_media' => 'We do not hold a social media profile for this business.',
            $normalised === 'company.registration_number' => 'We do not hold a company registration number for this business. Add one and it will be submitted automatically.',
            $normalised === 'person.id_number' => 'We do not hold an ID number for this business\'s owner. Add one and it will be submitted automatically.',
            $normalised === 'person.proof_of_address' => 'A proof of address has to be uploaded for this, so it cannot be filled in from your details.',
            default => null,
        };
    }

    /**
     * A raw provider field path, as this class refers to it.
     *
     * The provider keys person requirements by its own person id, which nobody
     * outside this feature can act on — so the id is folded out here rather than
     * at every call site. `persons.per_abc.id_document` and
     * `persons.per_xyz.id_document` are the same requirement about the same
     * thing, and would otherwise need two entries saying so.
     */
    public static function normalise(string $field): string
    {
        $field = trim($field);

        if (preg_match('/^persons\.([A-Za-z0-9_]+)\.(.+)$/', $field, $matches) === 1) {
            return 'person.'.$matches[2];
        }

        // A person requirement written without a person id: `persons.bvn`,
        // `persons.proof_of_address`.
        if (str_starts_with($field, 'persons.')) {
            return 'person.'.substr($field, strlen('persons.'));
        }

        return $field;
    }
}
