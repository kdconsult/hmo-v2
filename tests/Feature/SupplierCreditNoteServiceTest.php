<?php

declare(strict_types=1);

use App\Enums\PricingMode;
use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\SupplierCreditNoteService;
use App\Services\TenantOnboardingService;

test('recalculateItemTotals computes correct vat-exclusive values for credit note item', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = SupplierInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);
        $invoiceItem = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $creditNote = SupplierCreditNote::factory()->draft()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $item = SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $creditNote->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '3.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['supplierCreditNote', 'vatRate']);
        app(SupplierCreditNoteService::class)->recalculateItemTotals($item);
        $item->refresh();

        // base = 3 * 50 = 150; vat = 30; gross = 180
        expect((float) $item->line_total)->toBe(150.0)
            ->and((float) $item->vat_amount)->toBe(30.0)
            ->and((float) $item->line_total_with_vat)->toBe(180.0);
    });
});

test('recalculateItemTotals computes correct vat-inclusive values for credit note item', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = SupplierInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatInclusive,
        ]);
        $invoiceItem = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $creditNote = SupplierCreditNote::factory()->draft()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatInclusive,
        ]);

        $item = SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $creditNote->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '1.0000',
            'unit_price' => '120.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['supplierCreditNote', 'vatRate']);
        app(SupplierCreditNoteService::class)->recalculateItemTotals($item);
        $item->refresh();

        // gross = 120, vat = 20, net = 100
        expect((float) $item->line_total)->toBe(100.0)
            ->and((float) $item->vat_amount)->toBe(20.0)
            ->and((float) $item->line_total_with_vat)->toBe(120.0);
    });
});

test('recalculateDocumentTotals aggregates credit note items correctly', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = SupplierInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);
        $invoiceItem = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $creditNote = SupplierCreditNote::factory()->draft()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $service = app(SupplierCreditNoteService::class);

        $item1 = SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $creditNote->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item1->loadMissing(['supplierCreditNote', 'vatRate']);
        $service->recalculateItemTotals($item1);

        $item2 = SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $creditNote->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '1.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item2->loadMissing(['supplierCreditNote', 'vatRate']);
        $service->recalculateItemTotals($item2);

        $service->recalculateDocumentTotals($creditNote);
        $creditNote->refresh();

        // item1: net=200, vat=40; item2: net=50, vat=10 → subtotal=250, tax=50, total=300
        expect((float) $creditNote->subtotal)->toBe(250.0)
            ->and((float) $creditNote->tax_amount)->toBe(50.0)
            ->and((float) $creditNote->total)->toBe(300.0);
    });
});

test('creditedQuantity excludes items from cancelled credit notes', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 0.00]);
        $invoice = SupplierInvoice::factory()->confirmed()->create();
        $invoiceItem = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Confirmed CN: counts toward credited quantity
        $confirmedCn = SupplierCreditNote::factory()->confirmed()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $confirmedCn->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '4.0000',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '0.00',
            'line_total' => '0.00',
            'line_total_with_vat' => '0.00',
        ]);

        // Cancelled CN: must NOT count toward credited quantity
        $cancelledCn = SupplierCreditNote::factory()->cancelled()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $cancelledCn->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '0.00',
            'line_total' => '0.00',
            'line_total_with_vat' => '0.00',
        ]);

        $invoiceItem->refresh();

        // Only confirmed CN's 4 units count; cancelled CN's 5 units are ignored
        expect($invoiceItem->creditedQuantity())->toBe('4.0000')
            ->and($invoiceItem->remainingCreditableQuantity())->toBe('6.0000');
    });
});
