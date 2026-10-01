<?php

namespace App\Services\Digital;

use App\Mail\DigitalDownloadMail;
use App\Models\DigitalDownload;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DigitalDeliveryService
{
    /**
     * Create download tokens for a paid order's digital items and email the buyer.
     * Idempotent: each order item gets at most one token.
     */
    public function deliver(Order $order): int
    {
        if (! $order->isFullyPaid()) {
            return 0;
        }

        $order->loadMissing(['items.product', 'customer']);

        $created = 0;

        foreach ($order->items as $item) {
            if (! $item->is_digital || ! $item->product_id) {
                continue;
            }

            if (DigitalDownload::where('order_item_id', $item->id)->exists()) {
                continue;
            }

            $product = $item->product;
            if (! $product) {
                continue;
            }

            DigitalDownload::create([
                'business_id' => $order->business_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_id' => $product->id,
                'customer_id' => $order->customer_id,
                'token' => Str::random(64),
                'download_count' => 0,
                'max_downloads' => $product->downloadLimit(),
                'expires_at' => now()->addDays($product->downloadExpiryDays()),
            ]);

            $created++;
        }

        if ($created > 0) {
            $this->sendEmail($order);
        }

        return $created;
    }

    /**
     * Deliver without letting failures break the payment flow.
     */
    public function deliverSafely(Order $order): void
    {
        try {
            $this->deliver($order);
        } catch (\Throwable $e) {
            Log::error('digital.delivery_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Re-send the download email for an order that already has tokens.
     */
    public function resend(Order $order): bool
    {
        if (! $order->digitalDownloads()->exists()) {
            return false;
        }

        $this->sendEmail($order);

        return true;
    }

    private function sendEmail(Order $order): void
    {
        try {
            $order->loadMissing(['customer', 'digitalDownloads.product', 'store']);

            $email = $order->customer?->email;

            if (! $email) {
                return;
            }

            Mail::to($email)->queue(new DigitalDownloadMail($order));
        } catch (\Throwable $e) {
            Log::error('digital.download_mail_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
