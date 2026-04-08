<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantScheduledForDeletionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Tenant $tenant) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Final notice: Your account will be deleted',
        );
    }

    public function content(): Content
    {
        // TODO: create resources/views/mail/tenant/scheduled-for-deletion.blade.php
        return new Content(view: 'mail.tenant.scheduled-for-deletion');
    }
}
