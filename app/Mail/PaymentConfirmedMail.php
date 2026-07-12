<?php

namespace App\Mail;

use App\Models\Transaction;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Transaction $transaction;
    public Order $order;
    public $recipient;
    public Store $store;

    /**
     * Create a new message instance.
     */
    public function __construct(Transaction $transaction, Order $order, $recipient, Store $store)
    {
        $this->transaction = $transaction;
        $this->order = $order;
        $this->recipient = $recipient;
        $this->store = $store;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Confirmed for Order {$this->order->order_number}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order.payment-confirmed',
            with: [
                'transaction' => $this->transaction,
                'order' => $this->order,
                'recipient' => $this->recipient,
                'store' => $this->store,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
