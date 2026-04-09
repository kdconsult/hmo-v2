<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProformaInvoice extends Mailable
{
    use Queueable, SerializesModels;

    public readonly string $paymentReference;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $user,
        public readonly Plan $plan,
    ) {
        $this->paymentReference = "{$tenant->slug}-{$plan->slug}-".now()->format('Ym');
    }

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
                'paymentReference' => $this->paymentReference,
                'bankIban' => config('hmo.bank_iban'),
                'bankBic' => config('hmo.bank_bic'),
                'bankName' => config('hmo.bank_name'),
            ],
        );
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('pdf.proforma-invoice', [
            'tenant' => $this->tenant,
            'plan' => $this->plan,
            'paymentReference' => $this->paymentReference,
        ]);

        return [
            Attachment::fromData(
                fn () => $pdf->output(),
                "proforma-{$this->paymentReference}.pdf"
            )->withMime('application/pdf'),
        ];
    }
}
