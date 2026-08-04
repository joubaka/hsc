<?php

namespace App\Mail;

use App\Models\CommunicationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use App\Models\CommunicationSetting;
use Illuminate\Queue\SerializesModels;

class CommunicationMail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public CommunicationDelivery $delivery) {}
    public function envelope(): Envelope
    {
        $settings = CommunicationSetting::current();
        return new Envelope(
            from: $settings->sender_email ? new Address($settings->sender_email, $settings->sender_name) : null,
            replyTo: $settings->reply_to_email ? [new Address($settings->reply_to_email, $settings->sender_name)] : [],
            subject: $this->delivery->subject,
        );
    }
    public function content(): Content { return new Content(view: 'emails.communication', with: ['delivery' => $this->delivery, 'settings' => CommunicationSetting::current()]); }
}
