<?php

namespace App\Mail;

use App\Models\Coupon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponExhaustedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Coupon $coupon) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Coupon \"{$this->coupon->code}\" fully redeemed",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.coupon.exhausted',
            with: [
                'coupon' => $this->coupon,
            ],
        );
    }
}
