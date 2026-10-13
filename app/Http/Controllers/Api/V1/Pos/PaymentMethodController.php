<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Money\StoreCurrency;
use App\Support\Payments\PaymentGatewayRegistry;
use App\Support\Payments\SystemProcessor;
use Illuminate\Http\JsonResponse;

/**
 * The payment options a till may take for this store.
 *
 * The POS app used to hard-code its list in two places, and the two disagreed:
 * checkout offered `cash, paystack, transfer` while invoice payments offered
 * `cash, bank_transfer, cheque`. Neither read the store's configuration, so a
 * provider a business connected was invisible at the till.
 *
 * ## Why this is not the storefront's list
 *
 * It used to be: the till resolved through the same
 * {@see PaymentGatewayResolver::forStore()} the shopfront uses, so the two
 * agreed on what a store accepts by construction. That is the wrong guarantee
 * for a counter. A sale rung up at a till is not collected on Storify's rails —
 * the money is the business's, in the business's own bank account, and Storify
 * takes no cut of it and never holds it. Offering a card provider here means
 * collecting into the wrong account and then having to move the money, which is
 * the one thing a counter sale should never need.
 *
 * So the list is two entries and both are money Storify can neither see nor
 * split:
 *
 * - `cash`, first and always present, unless the platform is processing every
 *   payment itself. It is money in hand, it needs no provider, and a till that
 *   cannot record a cash sale is not a till.
 * - `bank_transfer`, the customer transferring into a bank account **of the
 *   business's own**. The gate on it is not the storefront's enablement but
 *   whether the business has an account at all: a business that switched bank
 *   transfer off online still takes transfers over the counter, because the
 *   counter is a different rail and the condition that matters is having
 *   somewhere to send the money.
 *
 * Under an *enforced system processor* the till offers nothing at all. A
 * processor means Storify is collecting for this business, and neither cash nor
 * a transfer into the business's own account is money it can see, split or
 * credit — the switch rules both out for the same reason. The reason is put on
 * the wire so the cashier can say something other than "the app is broken".
 *
 * The card endpoints ({@see PaymentController}) are untouched by this. They
 * remain for other clients; the till simply stops being offered those methods.
 */
final class PaymentMethodController extends Controller
{
    /**
     * The catalogue code for "a person transferred money to the business's
     * bank". Reused rather than given a POS-only code: it is already registered,
     * already what `ProcessPosSale` normalises to, and already what the POS app
     * has an icon for.
     */
    private const BANK_TRANSFER = 'bank_transfer';

    /**
     * Shown when the rail was withheld because the business has nowhere to
     * receive a transfer. Deliberately not the value of `unavailable_reason`:
     * cash is still on the list, so the till is not down — only this one option
     * is missing, and the cashier is being told how to get it back.
     */
    private const NO_BANK_ACCOUNT_REASON = 'Add a bank account to accept bank transfers at the till.';

    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
    ) {}

    public function index(Store $store): JsonResponse
    {
        $storeCurrency = StoreCurrency::for($store);
        $enforced = SystemProcessor::stored();

        $methods = [];
        $bankTransferUnavailableReason = null;

        if ($enforced === null) {
            // `cash` is the POS's own method, not a gateway — it is never in the
            // provider catalogue, so it is written here rather than resolved. It
            // is taken in the store's own currency and never converted.
            $methods[] = [
                'code' => 'cash',
                'name' => 'Cash',
                'mode' => 'offline',
                'requires_bank_account' => false,
                'currency' => $storeCurrency,
                'conversion' => null,
            ];

            $definition = PaymentGatewayRegistry::get(self::BANK_TRANSFER);

            if ($definition !== null) {
                $bankTransferUnavailableReason = $this->withheldBankTransferReason($store, $storeCurrency, $definition);

                if ($bankTransferUnavailableReason === null) {
                    $methods[] = [
                        'code' => self::BANK_TRANSFER,
                        'name' => $definition['name'],
                        'mode' => PaymentGatewayRegistry::checkoutModeFor(self::BANK_TRANSFER),
                        // The till asks for a bank account only when the method
                        // sends money to one, rather than by matching the method's
                        // name. It is always true here, and derived so that it
                        // stays true if the catalogue ever moves this rail.
                        'requires_bank_account' => ($definition['credential_source'] ?? 'keys') === 'bank_accounts',
                        // The store's own currency, not the rail's. The transfer
                        // is made into an account in the store's country, and an
                        // offline rail is never converted — the till must not
                        // show a converted figure for one.
                        'currency' => $storeCurrency,
                        'conversion' => null,
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'store_currency' => $storeCurrency,
                // The same sentence the storefront shows, from the same method,
                // for the same state: a till whose list is empty has to be able
                // to tell the cashier whether to call support. Null in the
                // ordinary case — see
                // {@see PaymentGatewayResolver::storeUnavailableReason()}.
                'unavailable_reason' => $methods === [] ? $this->gateways->storeUnavailableReason($store) : null,
                // Null whenever the rail is on the list, and also under an
                // enforced processor — there the whole list is down and
                // `unavailable_reason` above is the one that explains it.
                'bank_transfer_unavailable_reason' => $bankTransferUnavailableReason,
                'payment_methods' => $methods,
            ],
        ]);
    }

    /**
     * Why the till is not being offered a bank transfer, or null when it is.
     *
     * One function decides and explains, so the entry on the list and the
     * sentence about its absence cannot disagree.
     *
     * @param  array<string, mixed>  $definition
     */
    private function withheldBankTransferReason(Store $store, string $storeCurrency, array $definition): ?string
    {
        $railCurrency = strtoupper((string) ($definition['currency'] ?? ''));

        // The one condition no action by the business can change. Said plainly
        // rather than reported as a missing bank account the business may well
        // have, which would send someone to add a second one for nothing.
        if ($railCurrency !== '' && strcasecmp($storeCurrency, $railCurrency) !== 0) {
            return "Bank transfers at the till are taken in {$railCurrency}, and this store prices in {$storeCurrency}.";
        }

        // The business's own accounts — the same relation the till's bank list
        // is read from, so the option appears exactly when there is somewhere
        // to send the money. Assigned stores are not consulted: an account is
        // the business's, and the payout destination is one of these.
        if (! $store->banks()->exists()) {
            return self::NO_BANK_ACCOUNT_REASON;
        }

        return null;
    }
}
