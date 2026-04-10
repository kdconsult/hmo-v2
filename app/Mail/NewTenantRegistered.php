<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use App\Support\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewTenantRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New tenant registered: {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        $landlordUrl = TenantUrl::central('landlord');

        return new Content(
            markdown: 'mail.tenant.new-tenant-registered',
            with: [
                'tenant' => $this->tenant,
                'landlordUrl' => $landlordUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
