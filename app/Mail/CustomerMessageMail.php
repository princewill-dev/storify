<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Queue\WithQueueConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message a business composed for its customer.
 *
 * Queued, and one job per recipient: a broadcast to several hundred customers
 * must not hold the request open, and one bad address must not stop the rest.
 * `WithQueueConfig` gives it the platform's retry and backoff settings.
 *
 * The From address is the platform's, not the business's. Per-business sending
 * domains need SPF and DKIM records that no business has set up, and sending
 * as a domain that has not authorised us is how a store's mail ends up in
 * spam. The business is named in the body and the reply-to stays the
 * platform's; giving a business its own envelope is a separate piece of work.
 */
class CustomerMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, WithQueueConfig;

    public function __construct(
        public Customer $customer,
        public CustomerMessage $message,
        public string $businessName,
    ) {
        $this->initQueueConfig();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->message->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer.message',
            with: [
                'customer' => $this->customer,
                'message_body' => $this->message->body,
                'business_name' => $this->businessName,
                // Shown only on marketing mail. A transactional message is a
                // reply to something the customer did, and offering to
                // unsubscribe from it would be offering to stop being answered.
                'show_opt_out' => $this->message->respectsConsent(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
