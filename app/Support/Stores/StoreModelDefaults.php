<?php

namespace App\Support\Stores;

/**
 * The store model a business asked for, read back off its signup answers.
 *
 * ## Why the create form stopped asking
 *
 * It used to put three checkboxes in front of the person — physical, online,
 * till — and ask a question the platform had already been answered. "How can
 * we be of help" collects exactly this on the screen before, stores it on the
 * business, and nothing but the create form's own pre-ticking ever read it. So
 * the form no longer asks, and this is what fills the silence.
 *
 * The rule lives here rather than in the controller so that the write path and
 * the options payload cannot disagree about what the answers mean — the form
 * shows this same payload back to the person, and a form describing one store
 * while the server creates another is the failure this class exists to make
 * impossible.
 *
 * ## The mapping
 *
 * `sell_in_store` is the only answer that needs a till or a counter, so it is
 * the only one that turns those on. Everything else is a way of selling that
 * happens through a storefront — `transfer_existing` and `dropshipping`
 * included, since both are shops that already sell somewhere, and neither
 * implies a place with a door. A business that sells only in store therefore
 * gets a till and no storefront, which is a real shape: the storefront is
 * switched on later from store settings if they want one.
 *
 * ## What an answer of nothing means
 *
 * Every intent `false`, which is the same result the create form produced when
 * a person ticked no boxes. That matters for businesses whose intents predate
 * the column, or that were never asked: they must keep getting the store the
 * old form gave them, not a guess. The caller is still expected to have its own
 * default for the storefront — this class reports what was asked for, and
 * reports nothing when nothing was asked.
 */
final class StoreModelDefaults
{
    /**
     * The answers that imply a storefront rather than a counter.
     *
     * Deliberately not `Business::ONBOARDING_INTENTS` minus `sell_in_store`:
     * that list is the validation contract and may grow, and a new answer
     * should be considered here on purpose rather than silently defaulting to
     * "publishes a storefront".
     */
    private const ONLINE_INTENTS = ['sell_online', 'sell_digital', 'dropshipping', 'transfer_existing'];

    /**
     * @param  array<int, string>|null  $intents  `businesses.onboarding_intents`.
     * @return array{has_website: bool, is_physical: bool, pos_enabled: bool}
     */
    public static function fromIntents(?array $intents): array
    {
        $intents = array_map('strval', $intents ?? []);

        // One answer, one effect. A stored duplicate — written by a build
        // before the setup endpoint de-duplicated — must not change anything.
        $intents = array_values(array_unique($intents));

        $inStore = in_array('sell_in_store', $intents, true);

        return [
            'has_website' => array_intersect($intents, self::ONLINE_INTENTS) !== [],
            'is_physical' => $inStore,
            // The till follows the counter rather than being asked about
            // separately: a business with a shop has somewhere to put one, and
            // it can be switched off in store settings by anyone who does not
            // want it.
            'pos_enabled' => $inStore,
        ];
    }
}
