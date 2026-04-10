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

class TrialExpiringSoon extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your trial ends in 3 days — {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        $loginUrl = TenantUrl::to($this->tenant->slug, 'admin');

        return new Content(
            markdown: 'mail.tenant.trial-expiring',
            with: [
                'tenant' => $this->tenant,
                'user' => $this->user,
                'loginUrl' => $loginUrl,
                'trialEndsAt' => $this->tenant->trial_ends_at,
                'daysLeft' => (int) now()->diffInDays($this->tenant->trial_ends_at),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
