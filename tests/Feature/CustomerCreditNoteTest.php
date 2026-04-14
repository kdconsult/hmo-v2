<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteItem;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerCreditNoteService;
use App\Services\TenantOnboardingService;

test('customer credit note can be created with required fields', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();
        $invoice = CustomerInvoice::factory()->create(['partner_id' => $partner->id]);

        $creditNote = CustomerCreditNote::factory()->create([
            'partner_id' => $partner->id,
            'customer_invoice_id' => $invoice->id,
        ]);

        expect($creditNote->status)->toBe(DocumentStatus::Draft)
            ->and($creditNote->isEditable())->toBeTrue()
            ->and($creditNote->partner_id)->toBe($partner->id);
    });
});

test('remainingCreditableQuantity equals invoice item quantity when no credit notes exist', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'quantity' => '5.0000',
        ]);

        expect($invoiceItem->remainingCreditableQuantity())->toBe('5.0000');
    });
});

test('remainingCreditableQuantity decreases after a credit note is applied', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'quantity' => '5.0000',
        ]);

        $creditNote = CustomerCreditNote::factory()->confirmed()->create([
            'customer_invoice_id' => $invoiceItem->customer_invoice_id,
        ]);

        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '2.0000',
        ]);

        expect($invoiceItem->remainingCreditableQuantity())->toBe('3.0000');
    });
});

test('multiple credit notes cumulatively reduce remainingCreditableQuantity', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'quantity' => '10.0000',
        ]);

        $creditNote1 = CustomerCreditNote::factory()->confirmed()->create([
            'customer_invoice_id' => $invoiceItem->customer_invoice_id,
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote1->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '3.0000',
        ]);

        $creditNote2 = CustomerCreditNote::factory()->confirmed()->create([
            'customer_invoice_id' => $invoiceItem->customer_invoice_id,
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote2->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '4.0000',
        ]);

        expect($invoiceItem->remainingCreditableQuantity())->toBe('3.0000');
    });
});

test('cancelled credit notes do not reduce remainingCreditableQuantity', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'quantity' => '5.0000',
        ]);

        $cancelledNote = CustomerCreditNote::factory()->cancelled()->create([
            'customer_invoice_id' => $invoiceItem->customer_invoice_id,
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $cancelledNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'quantity' => '3.0000',
        ]);

        expect($invoiceItem->remainingCreditableQuantity())->toBe('5.0000');
    });
});

test('recalculateItemTotals computes correct VAT-exclusive totals', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->standard()->create(['rate' => '20.00']);

        $creditNote = CustomerCreditNote::factory()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $item = CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'quantity' => '2.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->setRelation('customerCreditNote', $creditNote);
        $item->setRelation('vatRate', $vatRate);

        app(CustomerCreditNoteService::class)->recalculateItemTotals($item);
        $item->refresh();

        // qty=2, price=50 → base=100; VAT 20% → vat=20, gross=120
        expect((float) $item->line_total)->toBe(100.0)
            ->and((float) $item->vat_amount)->toBe(20.0)
            ->and((float) $item->line_total_with_vat)->toBe(120.0);
    });
});

test('recalculateDocumentTotals aggregates credit note item amounts', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $creditNote = CustomerCreditNote::factory()->create();

        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => CustomerInvoiceItem::factory()->create([
                'customer_invoice_id' => $creditNote->customer_invoice_id,
            ])->id,
            'line_total' => '100.00',
            'vat_amount' => '20.00',
            'line_total_with_vat' => '120.00',
        ]);

        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => CustomerInvoiceItem::factory()->create([
                'customer_invoice_id' => $creditNote->customer_invoice_id,
            ])->id,
            'line_total' => '200.00',
            'vat_amount' => '40.00',
            'line_total_with_vat' => '240.00',
        ]);

        app(CustomerCreditNoteService::class)->recalculateDocumentTotals($creditNote);
        $creditNote->refresh();

        expect((float) $creditNote->subtotal)->toBe(300.0)
            ->and((float) $creditNote->tax_amount)->toBe(60.0)
            ->and((float) $creditNote->total)->toBe(360.0);
    });
});
