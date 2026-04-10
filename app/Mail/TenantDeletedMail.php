<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantDeletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $tenantName) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your account has been permanently deleted',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.tenant.deleted',
            with: ['tenantName' => $this->tenantName],
        );
    }
}
