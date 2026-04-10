<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeTenant extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Welcome to {$this->tenant->name} — Your 14-day trial has started",
        );
    }

    public function content(): Content
    {
        $loginUrl = TenantUrl::to($this->tenant->slug, 'admin');

        return new Content(
            markdown: 'mail.tenant.welcome',
            with: [
                'tenant' => $this->tenant,
                'user' => $this->user,
                'loginUrl' => $loginUrl,
                'trialEndsAt' => $this->tenant->trial_ends_at,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
