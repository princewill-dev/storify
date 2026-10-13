<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Api\V1\Pos\Concerns\ResolvesPosTerminal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosPaymentInitializeRequest;
use App\Http\Requests\Pos\PosPaymentStatusRequest;
use App\Models\Store;
use App\Services\Pos\PosPaymentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Opening a provider's checkout from the till, and asking it what happened.
 *
 * Two endpoints, neither of which writes a row, and **neither of which the POS
 * app calls any more**. The till takes cash and a transfer into the business's
 * own bank account — see {@see PaymentMethodController} — so it never opens a
 * provider and never has a reference to poll.
 *
 * They are kept because they are the till's card path and other clients use
 * them: `initialize` starts a charge and returns a URL, `status` asks the
 * provider what became of it. Anyone restoring card at the till gets the first
 * half back for free; the half that matters is
 * {@see PosCheckoutRequest}, whose accepted methods are what make a card leg
 * unrepresentable rather than merely unverified.
 *
 * Both are gated the same way `/checkout` is: an authenticated terminal token
 * bound to this store, and an open drawer. A card charge is a sale in progress,
 * so it takes the same authorisation a sale does.
 */
final class PaymentController extends Controller
{
    use ResolvesPosTerminal;

    public function __construct(private readonly PosPaymentService $payments) {}

    public function initialize(PosPaymentInitializeRequest $request, Store $store): JsonResponse
    {
        $user = $request->user();

        $session = $this->openSessionFor($store, $user);
        if (! $session) {
            return response()->json(['success' => false, 'message' => 'Please open a POS session first.'], 400);
        }

        $data = $request->validated();

        // Before the provider is asked for anything. A cashier whose PIN does
        // not check out must not be able to move a customer's money, and the
        // check has to happen here rather than at `/checkout` because by then
        // it would be too late to be worth anything.
        if (! $this->pinIsValid($user, $data['pin'] ?? null)) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN.'], 422);
        }

        try {
            // The amount is the server's, not the till's. Whatever the cashier
            // meant to charge, the basket is what is being sold.
            $quote = $this->payments->quote(
                $store,
                $data['items'],
                $data['service_charge_id'] ?? null,
            );

            $amount = (float) $data['amount'];

            if ($amount > $quote->total + 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'That is more than this sale comes to ('.number_format($quote->total, 2).').',
                ], 422);
            }

            $payment = $this->payments->initialize($store, $user, $data, $amount);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('pos.payment_initialize_failed', [
                'store_id' => $store->id,
                'staff_id' => $user?->id,
                'method' => $data['method'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'The payment could not be started.'], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'mode' => $payment['mode'],
                'authorization_url' => $payment['authorization_url'],
                'reference' => $payment['reference'],
                'access_code' => $payment['access_code'],
                // Echoed back by the till on every later status check and on
                // checkout. Null unless the provider named this payment itself.
                'provider_reference' => $payment['provider_reference'],
                'amount' => $payment['amount'],
                // What the provider was asked to charge, in its own minor
                // units, alongside the currency and the rate that produced it.
                // The till echoes `charge_amount` back on every status check
                // and on the sale itself.
                'charge_amount' => $payment['charge_amount'],
                'charge_currency' => $payment['charge_currency'],
                'rate' => $payment['rate'],
                // What the sale actually comes to, so the till can show a
                // running balance that matches the one the order will carry.
                'total' => $quote->total,
            ],
        ], 201);
    }

    public function status(PosPaymentStatusRequest $request, Store $store): JsonResponse
    {
        $user = $request->user();

        if (! $this->openSessionFor($store, $user)) {
            return response()->json(['success' => false, 'message' => 'Please open a POS session first.'], 400);
        }

        $data = $request->validated();

        try {
            $status = $this->payments->status(
                $store,
                $data['method'],
                $data['reference'],
                (float) $data['amount'],
                $data['provider_reference'] ?? null,
                isset($data['charge_amount']) ? (int) $data['charge_amount'] : null,
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('pos.payment_status_failed', [
                'store_id' => $store->id,
                'reference' => $data['reference'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'The payment could not be checked.'], 500);
        }

        // A payment still in flight is a 200, not an error: the till is asking
        // a question whose honest answer is "not yet", and it will ask again.
        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }
}
