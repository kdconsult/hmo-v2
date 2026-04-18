<?php

declare(strict_types=1);

use App\Enums\PricingMode;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteItem;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerCreditNoteService;
use App\Services\TenantOnboardingService;

test('recalculateItemTotals computes correct vat-exclusive values for credit note item', function () {
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

        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $item = CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '3.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['customerCreditNote', 'vatRate']);
        app(CustomerCreditNoteService::class)->recalculateItemTotals($item);
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
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatInclusive,
        ]);
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '5.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatInclusive,
        ]);

        $item = CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '1.0000',
            'unit_price' => '120.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->loadMissing(['customerCreditNote', 'vatRate']);
        app(CustomerCreditNoteService::class)->recalculateItemTotals($item);
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
        $invoice = CustomerInvoice::factory()->confirmed()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $service = app(CustomerCreditNoteService::class);

        $item1 = CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item1->loadMissing(['customerCreditNote', 'vatRate']);
        $service->recalculateItemTotals($item1);

        $item2 = CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '1.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $vatRate->id,
        ]);
        $item2->loadMissing(['customerCreditNote', 'vatRate']);
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
        $invoice = CustomerInvoice::factory()->confirmed()->create();
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Confirmed CN: counts toward credited quantity
        $confirmedCn = CustomerCreditNote::factory()->confirmed()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $confirmedCn->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '4.0000',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '0.00',
            'line_total' => '0.00',
            'line_total_with_vat' => '0.00',
        ]);

        // Cancelled CN: must NOT count toward credited quantity
        $cancelledCn = CustomerCreditNote::factory()->cancelled()->create([
            'customer_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $cancelledCn->id,
            'customer_invoice_item_id' => $invoiceItem->id,
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

test('autoFillItemsFromInvoice creates credit note items from invoice items', function () {
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
            'quantity' => '5.0000',
            'unit_price' => '100.0000',
            'description' => 'Widget A',
            'vat_rate_id' => $vatRate->id,
        ]);

        $ccn = CustomerCreditNote::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        app(CustomerCreditNoteService::class)->autoFillItemsFromInvoice($ccn);

        $ccn->refresh();
        $items = $ccn->items()->get();

        expect($items)->toHaveCount(1);

        $item = $items->first();
        expect($item->customer_invoice_item_id)->toBe($invoiceItem->id)
            ->and($item->product_variant_id)->toBe($invoiceItem->product_variant_id)
            ->and($item->description)->toBe('Widget A')
            ->and((string) $item->quantity)->toBe('5.0000')
            ->and((string) $item->unit_price)->toBe('100.0000')
            ->and($item->vat_rate_id)->toBe($vatRate->id);

        // Totals must be recalculated: 5 * 100 = 500 net, 20% VAT = 100
        expect((float) $item->line_total)->toBe(500.0)
            ->and((float) $item->vat_amount)->toBe(100.0)
            ->and((float) $item->line_total_with_vat)->toBe(600.0);

        // Document totals also updated
        expect((float) $ccn->subtotal)->toBe(500.0)
            ->and((float) $ccn->tax_amount)->toBe(100.0)
            ->and((float) $ccn->total)->toBe(600.0);
    });
});

test('autoFillItemsFromInvoice skips invoice items already fully credited', function () {
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
            'quantity' => '5.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        // Fully credit the item via a confirmed CCN
        $existingCcn = CustomerCreditNote::factory()->confirmed()->create([
            'customer_invoice_id' => $invoice->id,
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $existingCcn->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '5.0000',
        ]);

        $newCcn = CustomerCreditNote::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        app(CustomerCreditNoteService::class)->autoFillItemsFromInvoice($newCcn);

        expect($newCcn->items()->count())->toBe(0);
    });
});
