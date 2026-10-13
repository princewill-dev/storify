<?php

namespace App\Support\Payments;

use App\Models\Setting;
use App\Services\Payments\Connect\PlatformCommission;
use App\Services\Subscription\PlatformBillingGateway;

/**
 * The processor Storify takes *every* business's customer money through — the
 * platform-wide switch an admin sets, or nothing at all.
 *
 * ## Two states, and a third that is neither
 *
 * `enforced()` is false when the column is empty, which is the platform's
 * behaviour since it had payment providers: each business charges through
 * whatever it has connected, and Storify is not in the money path at all. That
 * is the state a deployment is in until an admin deliberately changes it — but
 * it is **not** a state in which a business Storify collects for can sell, since
 * the rail that used to serve those businesses from a per-business account was
 * deleted. Custody and enforcement are two halves of one arrangement, and the
 * end of the migration is a processor selected.
 *
 * When enforced, `code()` names the processor and every other provider — and
 * every offline rail — stops being offered. A business's own keys are ignored,
 * and a business that Storify does not collect for takes no payments at all:
 * there is no longer a way to charge through the platform while the gross
 * settles to the business, so the two must agree.
 *
 * The third state is a column holding a name that is not a processor this build
 * can run — a provider removed from the registry while selected, or a
 * hand-edited row. **That reads as enforced with no processor, not as off, and
 * the difference matters.** Quietly falling back to the old per-business rules
 * would take money across a boundary an admin believes they closed; refusing
 * everything is loud, recoverable, and says so through
 * {@see self::unavailableReason()}. Fail closed, exactly as
 * {@see PlatformBillingGateway} does when its keys
 * are missing.
 *
 * ## Read on every call, deliberately un-memoised
 *
 * The value is admin-mutable state: an admin flipping the switch expects the
 * next checkout — and the next webhook — to follow it. A memo would make the
 * behaviour depend on *when the object was built*, which is harmless on a
 * classic request-per-process deployment and quietly wrong under a persistent
 * worker, where the instance outlives the request. One cheap query per call
 * buys the absence of that class of bug. The same reasoning, and the same
 * choice, is in {@see PlatformBillingGateway::code()}
 * and {@see PlatformCommission::fromSettings()}.
 *
 * Static like {@see GatewayGrant}, because the resolver, the driver guards, the
 * POS list, the admin pre-flight and the business screen all have to agree on
 * this one fact, and a service either of them could construct differently is a
 * service two of them would.
 */
final class SystemProcessor
{
    /**
     * What an admin selected, verbatim — NULL when the switch is off.
     *
     * Kept separate from {@see self::code()} so the two questions "is the
     * platform enforcing a processor?" and "can this build actually run it?"
     * stay distinct. Collapsing them is how an unknown name silently becomes
     * "off".
     */
    public static function stored(): ?string
    {
        $configured = Setting::query()->value('system_payment_processor');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * The selected processor, when this build can run it — else null.
     *
     * A name the platform holds no credentials for is not a processor it can
     * charge through, so it does not count as one, however it got into the
     * column. {@see self::enforced()} is what says whether it is set at all.
     */
    public static function code(): ?string
    {
        $stored = self::stored();

        return $stored !== null && PlatformBillingGateway::canChargeThrough($stored) ? $stored : null;
    }

    /**
     * Whether the platform is processing every business's inflow through one
     * processor — including when the selected one cannot be run, which is why
     * this is not `code() !== null`.
     */
    public static function enforced(): bool
    {
        return self::stored() !== null;
    }

    public static function is(string $code): bool
    {
        return self::code() === $code;
    }

    /** The display name, from the same catalogue the business screens render. */
    public static function name(): ?string
    {
        $code = self::code();

        return $code === null ? null : (string) (PaymentGatewayRegistry::get($code)['name'] ?? $code);
    }

    /**
     * Why the platform cannot process a payment right now, or null when it can
     * — or when it is not enforcing at all.
     *
     * A message rather than a boolean because every caller has to *say*
     * something: a customer at an empty checkout, a business on its payment
     * screen, an admin reading the console. Returning the reason keeps the three
     * from inventing different explanations for one state.
     */
    public static function unavailableReason(): ?string
    {
        $stored = self::stored();

        if ($stored === null) {
            return null;
        }

        if (self::code() !== null) {
            return null;
        }

        return "Storify processes all payments through {$stored}, which this build cannot use. No payments can be taken until an admin changes it.";
    }

    /**
     * The processors an admin may choose between.
     *
     * The providers the platform holds its own credentials for and nothing else:
     * a provider the platform could only charge through with a *business's* keys
     * has no meaning as the platform's processor, because there would be no
     * Storify account for the money to land in.
     *
     * @return array<int, string>
     */
    public static function selectableCodes(): array
    {
        return PlatformBillingGateway::selectableCodes();
    }
}
