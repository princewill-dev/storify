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

class RefundProcessedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Transaction $transaction;
    public Order $order;
    public Customer $customer;
    public Store $store;
    public string $reason;

    /**
     * Create a new message instance.
     */
    public function __construct(Transaction $transaction, Order $order, Customer $customer, Store $store, string $reason)
    {
        $this->transaction = $transaction;
        $this->order = $order;
        $this->customer = $customer;
        $this->store = $store;
        $this->reason = $reason;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Refund Processed for Order {$this->order->order_number}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order.refund-processed',
            with: [
                'transaction' => $this->transaction,
                'order' => $this->order,
                'customer' => $this->customer,
                'store' => $this->store,
                'reason' => $this->reason,
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
