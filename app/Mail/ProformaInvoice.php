<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProformaInvoice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $user,
        public readonly Plan $plan,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Proforma Invoice — {$this->plan->name} Plan — {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.tenant.proforma-invoice',
            with: [
                'tenant' => $this->tenant,
                'user' => $this->user,
                'plan' => $this->plan,
                'amount' => $this->plan->price,
                'billingPeriod' => $this->plan->billing_period,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
