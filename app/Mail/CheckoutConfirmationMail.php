<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CheckoutConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Address $smtpFrom,
        public readonly ?string $plainPassword = null,
        public readonly ?string $invoicePdfBinary = null,
        public readonly ?string $invoicePdfFilename = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->smtpFrom,
            subject: 'Your booking order confirmation #'.$this->order->id,
        );
    }

    public function content(): Content
    {
        $frontendBaseUrl = rtrim((string) config('workatmo.frontend_url', config('app.url')), '/');
        $loginUrl = $frontendBaseUrl.'/login';
        $forgotPasswordUrl = $frontendBaseUrl.'/forgot-password';

        return new Content(
            view: 'emails.checkout_confirmation',
            with: [
                'order' => $this->order,
                'plainPassword' => $this->plainPassword,
                'bookingSlot' => $this->order->slot,
                'customer' => $this->order->user,
                'loginUrl' => $loginUrl,
                'forgotPasswordUrl' => $forgotPasswordUrl,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->invoicePdfBinary || ! $this->invoicePdfFilename) {
            return [];
        }

        return [
            Attachment::fromData(
                fn (): string => $this->invoicePdfBinary,
                $this->invoicePdfFilename
            )->withMime('application/pdf'),
        ];
    }
}
