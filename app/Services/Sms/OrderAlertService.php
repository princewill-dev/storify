<?php

namespace App\Services\Sms;

use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\KycPhoneVerification;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money\StoreCurrency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Transactional SMS about money and orders.
 *
 * Everything here is best-effort and at-most-once. The dispatcher claims a
 * dedupe key before the message is queued, so a webhook redelivery cannot send
 * a second copy; these methods additionally sit behind the settlement paths'
 * own `CONFIRMED` guards. A failure is logged and dropped — the payment and the
 * order transition have already happened and must not be undone by an SMS.
 *
 * POS sales are deliberately absent: the till already shows the cashier the
 * sale, and the owner does not want a text per walk-in.
 */
final class OrderAlertService
{
    public function __construct(private readonly SmsDispatcher $sms) {}

    public function orderPaid(Transaction $transaction): void
    {
        $this->safely('order_paid', function () use ($transaction): void {
            $order = $transaction->order;
            $business = $order?->business;

            if (! $order || ! $business || $order->isPos()) {
                return;
            }

            $store = $order->store;
            $currency = $store !== null
                ? StoreCurrency::for($store)
                : strtoupper((string) ($business->currency ?: StoreCurrency::DEFAULT));
            $amount = $this->amount($transaction->amount);
            $owner = $business->owner ?? $store?->user;

            if ($owner !== null) {
                $this->sms->send(
                    $business,
                    $this->ownerPhone($owner, $business->id),
                    SmsMessage::TYPE_ORDER_PAID_OWNER,
                    'sms.order.paid:transaction:'.$transaction->id,
                    sprintf(
                        'Storify: New order %s paid at %s: %s %s. Customer: %s.',
                        $order->order_number,
                        $store?->name ?? $business->name,
                        $currency,
                        $amount,
                        $this->customerName($order) ?: 'guest',
                    ),
                    $owner->id,
                );
            }

            if (! $order->isFullyPaid()) {
                return;
            }

            $customerPhone = $order->customer?->phone;

            if (blank($customerPhone)) {
                return;
            }

            $isFirstPaidOrder = ! Order::query()
                ->where('customer_id', $order->customer_id)
                ->where('id', '!=', $order->id)
                ->where('amount_paid', '>', 0)
                ->exists();

            if (! $isFirstPaidOrder) {
                return;
            }

            $this->sms->send(
                $business,
                $customerPhone,
                SmsMessage::TYPE_ORDER_PAID_CUSTOMER_FIRST,
                'sms.order.first:customer:'.$order->customer_id,
                sprintf(
                    'Storify: Thank you %s! Your first order %s at %s is confirmed. Total %s %s. We will text you updates.',
                    $this->customerFirstName($order) ?: 'there',
                    $order->order_number,
                    $store?->name ?? $business->name,
                    $currency,
                    $this->amount($order->total),
                ),
                customerId: $order->customer_id,
            );
        });
    }

    public function invoicePaid(Transaction $transaction): void
    {
        $this->safely('invoice_paid', function () use ($transaction): void {
            $invoice = $transaction->invoice;
            $business = $invoice?->business;

            if (! $invoice || ! $business) {
                return;
            }

            $owner = $business->owner
                ?? $invoice->store?->business?->owner
                ?? $invoice->store?->user;

            if ($owner === null) {
                return;
            }

            $currency = strtoupper((string) ($business->currency ?: StoreCurrency::DEFAULT));

            $this->sms->send(
                $business,
                $this->ownerPhone($owner, $business->id),
                SmsMessage::TYPE_INVOICE_PAID_OWNER,
                'sms.invoice.paid:transaction:'.$transaction->id,
                sprintf(
                    'Storify: Invoice %s payment received at %s: %s %s. Payer: %s.',
                    $invoice->invoice_number,
                    $business->name,
                    $currency,
                    $this->amount($transaction->amount),
                    $this->invoicePayer($invoice),
                ),
                $owner->id,
            );
        });
    }

    public function orderStatusChanged(Order $order, string $oldStatus, string $newStatus): void
    {
        $this->safely('order_status', function () use ($order, $newStatus): void {
            // `pending` is where an order is born; `accepted` is what a
            // successful payment writes, and the first-order acknowledgement
            // already speaks for it. Neither is news worth a second text.
            if ($order->isPos() || in_array($newStatus, [
                OrderStatus::PENDING->value,
                OrderStatus::ACCEPTED->value,
            ], true)) {
                return;
            }

            $business = $order->business;
            $customerPhone = $order->customer?->phone;

            if (! $business || blank($customerPhone)) {
                return;
            }

            $label = OrderStatus::tryFrom($newStatus)?->label() ?? Str::title($newStatus);

            $this->sms->send(
                $business,
                $customerPhone,
                SmsMessage::TYPE_ORDER_STATUS_CUSTOMER,
                'sms.order.status:order:'.$order->id.':'.$newStatus,
                sprintf(
                    'Storify: Order %s at %s is now %s. Reply to the store if you need help.',
                    $order->order_number,
                    $order->store?->name ?? $business->name,
                    $label,
                ),
                customerId: $order->customer_id,
            );
        });
    }

    /**
     * The owner's account phone, or the number they proved at KYC.
     *
     * The KYC number is the fallback because it is the one number on the
     * account somebody has actually demonstrated they control; an account
     * phone is free text until then.
     */
    private function ownerPhone(User $owner, int $businessId): string
    {
        if (filled($owner->phone)) {
            return (string) $owner->phone;
        }

        return (string) KycPhoneVerification::query()
            ->where('user_id', $owner->id)
            ->where('business_id', $businessId)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->value('phone');
    }

    private function customerName(Order $order): string
    {
        return trim((string) ($order->customer?->full_name ?? ''));
    }

    private function customerFirstName(Order $order): string
    {
        return trim((string) ($order->customer?->first_name ?? ''));
    }

    private function invoicePayer(Invoice $invoice): string
    {
        return trim((string) ($invoice->recipient_name ?: $invoice->customer?->full_name ?: 'customer'));
    }

    private function amount(mixed $amount): string
    {
        return number_format((float) $amount, 2);
    }

    /**
     * A notification failure is logged, never thrown. Its callers are in the
     * middle of recording a payment or completing a transition.
     */
    private function safely(string $context, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::error('sms.alert_failed', [
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
