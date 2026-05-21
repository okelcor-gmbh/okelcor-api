<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentMilestoneUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $subjectLine;

    public readonly string $firstName;

    public function __construct(
        public readonly Order $order,
        public readonly string $stage,
    ) {
        $this->subjectLine = $this->resolveSubject();
        $this->firstName   = explode(' ', trim($order->customer_name))[0] ?: 'Customer';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'support@okelcor.com'),
                config('mail.from.name', 'Okelcor'),
            ),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'https://okelcor.com'), '/');

        return new Content(
            html: 'emails.payment-milestone',
            text: 'emails.payment-milestone-text',
            with: [
                'order'       => $this->order,
                'stage'       => $this->stage,
                'firstName'   => $this->firstName,
                'orderUrl'    => $frontendUrl . '/account/orders/' . $this->order->ref,
                'bank'        => config('payment.bank_transfer'),
            ],
        );
    }

    private function resolveSubject(): string
    {
        return match ($this->stage) {
            'deposit_requested' => 'Deposit requested for order ' . $this->order->ref,
            'deposit_paid'      => 'Deposit received for order ' . $this->order->ref,
            'balance_due'       => 'Balance payment due for order ' . $this->order->ref,
            'balance_paid'      => 'Balance received for order ' . $this->order->ref,
            'shipment_released' => 'Shipment released for order ' . $this->order->ref,
            default             => 'Payment update for order ' . $this->order->ref,
        };
    }
}
