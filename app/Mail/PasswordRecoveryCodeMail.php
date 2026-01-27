<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordRecoveryCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password recovery code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_recovery_code',
            with: [
                'code' => $this->code,
            ],
        );
    }
}
