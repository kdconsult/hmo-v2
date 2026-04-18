<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Models\CompanySettings;
use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerCreditNoteService;
use App\Services\CustomerDebitNoteService;
use App\Services\CustomerInvoiceService;
use App\Services\DocumentHasher;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->vatRegistered()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

// ─── Invoice hash ─────────────────────────────────────────────────────────────

test('confirmWithScenario pins document_hash on a customer invoice', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);

        $zeroRate = VatRate::where('rate', 0)->first();
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '2.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $zeroRate?->id,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        $invoice->refresh();

        expect($invoice->document_hash)
            ->not->toBeNull()
            ->toHaveLength(64)
            ->toMatch('/^[0-9a-f]{64}$/');
    });
});

test('confirmWithScenario sets exchange_rate_source to fixed_eur for EUR invoices', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        expect($invoice->refresh()->exchange_rate_source)->toBe('fixed_eur');
    });
});

test('stored document_hash matches DocumentHasher recomputation', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);

        $zeroRate = VatRate::where('rate', 0)->first();
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '3.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $zeroRate?->id,
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);

        $invoice->refresh()->load('items');

        expect(DocumentHasher::forInvoice($invoice))->toBe($invoice->document_hash);
    });
});

test('document_hash differs between invoices with different totals', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $inv1 = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
            'total' => '100.00',
        ]);

        $inv2 = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
            'total' => '200.00',
        ]);

        app(CustomerInvoiceService::class)->confirmWithScenario($inv1);
        app(CustomerInvoiceService::class)->confirmWithScenario($inv2);

        expect($inv1->refresh()->document_hash)->not->toBe($inv2->refresh()->document_hash);
    });
});

// ─── Credit note hash ─────────────────────────────────────────────────────────

test('confirmWithScenario pins document_hash on a customer credit note', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $parentInvoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);
        app(CustomerInvoiceService::class)->confirmWithScenario($parentInvoice);
        $parentInvoice->refresh();

        $note = CustomerCreditNote::factory()->create([
            'customer_invoice_id' => $parentInvoice->id,
            'partner_id' => $partner->id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
        ]);
        app(CustomerCreditNoteService::class)->confirmWithScenario($note);

        $note->refresh();

        expect($note->document_hash)
            ->not->toBeNull()
            ->toHaveLength(64)
            ->toMatch('/^[0-9a-f]{64}$/');
    });
});

test('credit note hash chains the parent invoice document_hash', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $parentInvoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);
        app(CustomerInvoiceService::class)->confirmWithScenario($parentInvoice);
        $parentInvoice->refresh();

        $note = CustomerCreditNote::factory()->create([
            'customer_invoice_id' => $parentInvoice->id,
            'partner_id' => $partner->id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
        ]);
        app(CustomerCreditNoteService::class)->confirmWithScenario($note);
        $note->refresh()->load('items');

        // Recomputation with correct parent matches stored hash.
        expect(DocumentHasher::forCreditNote($note, $parentInvoice))->toBe($note->document_hash);

        // Tampering the parent hash breaks the chain.
        $parentInvoice->document_hash = str_repeat('0', 64);
        expect(DocumentHasher::forCreditNote($note, $parentInvoice))->not->toBe($note->document_hash);
    });
});

// ─── Debit note hash ──────────────────────────────────────────────────────────

test('confirmWithScenario pins document_hash on a customer debit note', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $parentInvoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);
        app(CustomerInvoiceService::class)->confirmWithScenario($parentInvoice);
        $parentInvoice->refresh();

        $note = CustomerDebitNote::factory()->create([
            'customer_invoice_id' => $parentInvoice->id,
            'partner_id' => $partner->id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
        ]);
        app(CustomerDebitNoteService::class)->confirmWithScenario($note);

        $note->refresh();

        expect($note->document_hash)
            ->not->toBeNull()
            ->toHaveLength(64);
    });
});

// ─── Integrity check command ──────────────────────────────────────────────────

test('hmo:integrity-check passes when all hashes are valid', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);
        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);
    });

    $this->artisan('hmo:integrity-check')->assertExitCode(0);
});

test('hmo:integrity-check fails when an invoice hash is tampered', function () {
    $invoiceId = null;

    $this->tenant->run(function () use (&$invoiceId) {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
            'currency_code' => 'EUR',
        ]);
        app(CustomerInvoiceService::class)->confirmWithScenario($invoice);
        $invoiceId = $invoice->id;
    });

    // Bypass guard to corrupt the hash (simulate tampering).
    $this->tenant->run(function () use ($invoiceId) {
        CustomerInvoice::where('id', $invoiceId)
            ->update(['document_hash' => str_repeat('a', 64)]);
    });

    $this->artisan('hmo:integrity-check')->assertExitCode(1);
});
