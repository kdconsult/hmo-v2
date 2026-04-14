<?php

declare(strict_types=1);

use App\Enums\PricingMode;
use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerDebitNoteService;
use App\Services\TenantOnboardingService;

test('recalculateItemTotals computes correct vat-exclusive values for debit note item', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $item = CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '2.0000',
            'unit_price' => '75.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['customerDebitNote', 'vatRate']);
        app(CustomerDebitNoteService::class)->recalculateItemTotals($item);
        $item->refresh();

        // base = 2 * 75 = 150; vat = 30; gross = 180
        expect((float) $item->line_total)->toBe(150.0)
            ->and((float) $item->vat_amount)->toBe(30.0)
            ->and((float) $item->line_total_with_vat)->toBe(180.0);
    });
});

test('recalculateItemTotals computes correct vat-inclusive values for debit note item', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatInclusive,
        ]);
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatInclusive,
        ]);

        $item = CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '1.0000',
            'unit_price' => '240.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['customerDebitNote', 'vatRate']);
        app(CustomerDebitNoteService::class)->recalculateItemTotals($item);
        $item->refresh();

        // gross = 240, vat = 40, net = 200
        expect((float) $item->line_total)->toBe(200.0)
            ->and((float) $item->vat_amount)->toBe(40.0)
            ->and((float) $item->line_total_with_vat)->toBe(240.0);
    });
});

test('recalculateDocumentTotals aggregates debit note items correctly', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $service = app(CustomerDebitNoteService::class);

        $item1 = CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '3.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item1->loadMissing(['customerDebitNote', 'vatRate']);
        $service->recalculateItemTotals($item1);

        $item2 = CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '1.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item2->loadMissing(['customerDebitNote', 'vatRate']);
        $service->recalculateItemTotals($item2);

        $service->recalculateDocumentTotals($debitNote);
        $debitNote->refresh();

        // item1: net=300, vat=60; item2: net=50, vat=10 → subtotal=350, tax=70, total=420
        expect((float) $debitNote->subtotal)->toBe(350.0)
            ->and((float) $debitNote->tax_amount)->toBe(70.0)
            ->and((float) $debitNote->total)->toBe(420.0);
    });
});

test('debit note without invoice link can be created independently', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => null,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $item = CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'customer_invoice_item_id' => null,
            'quantity' => '1.0000',
            'unit_price' => '500.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['customerDebitNote', 'vatRate']);
        app(CustomerDebitNoteService::class)->recalculateItemTotals($item);
        $item->refresh();

        // base = 500; vat = 100; gross = 600
        expect((float) $item->line_total)->toBe(500.0)
            ->and((float) $item->vat_amount)->toBe(100.0)
            ->and((float) $item->line_total_with_vat)->toBe(600.0);
    });
});
