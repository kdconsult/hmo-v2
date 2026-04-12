<?php

declare(strict_types=1);

use App\Enums\CreditNoteReason;
use App\Enums\DocumentStatus;
use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\TenantOnboardingService;

test('supplier credit note can be created against an invoice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = SupplierInvoice::factory()->confirmed()->create();

        $creditNote = SupplierCreditNote::create([
            'credit_note_number' => 'SCN-001',
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'status' => DocumentStatus::Draft,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'reason' => CreditNoteReason::Return,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'issued_at' => now()->toDateString(),
        ]);

        expect($creditNote->credit_note_number)->toBe('SCN-001')
            ->and($creditNote->isEditable())->toBeTrue()
            ->and($creditNote->supplierInvoice->id)->toBe($invoice->id);
    });
});

test('credit note item tracks credited quantity against invoice item', function () {
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

        expect($invoiceItem->creditedQuantity())->toBe('0')
            ->and($invoiceItem->remainingCreditableQuantity())->toBe('10.0000');

        $creditNote = SupplierCreditNote::factory()->draft()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $creditNote->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '6.0000',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '0.00',
            'line_total' => '0.00',
            'line_total_with_vat' => '0.00',
        ]);

        $invoiceItem->refresh();
        expect($invoiceItem->creditedQuantity())->toBe('6.0000')
            ->and($invoiceItem->remainingCreditableQuantity())->toBe('4.0000');
    });
});

test('credit note totals recalculate from items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->create(['rate' => 20.00]);
        $invoice = SupplierInvoice::factory()->confirmed()->create();
        $invoiceItem = SupplierInvoiceItem::factory()->create([
            'supplier_invoice_id' => $invoice->id,
            'quantity' => '10.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $creditNote = SupplierCreditNote::factory()->draft()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $creditNote->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '2.0000',
            'unit_price' => '50.0000',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '20.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '120.00',
        ]);

        $creditNote->load('items');
        $creditNote->recalculateTotals();
        $creditNote->refresh();

        expect((float) $creditNote->subtotal)->toBe(100.0)
            ->and((float) $creditNote->tax_amount)->toBe(20.0)
            ->and((float) $creditNote->total)->toBe(120.0);
    });
});

test('multiple credit notes sum credited quantities correctly', function () {
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

        // First credit note: 4 units
        $cn1 = SupplierCreditNote::factory()->confirmed()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $cn1->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '4.0000',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '0.00',
            'line_total' => '0.00',
            'line_total_with_vat' => '0.00',
        ]);

        // Second credit note: 3 units
        $cn2 = SupplierCreditNote::factory()->draft()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
        ]);
        SupplierCreditNoteItem::factory()->create([
            'supplier_credit_note_id' => $cn2->id,
            'supplier_invoice_item_id' => $invoiceItem->id,
            'quantity' => '3.0000',
            'vat_rate_id' => $vatRate->id,
            'vat_amount' => '0.00',
            'line_total' => '0.00',
            'line_total_with_vat' => '0.00',
        ]);

        $invoiceItem->refresh();
        expect($invoiceItem->creditedQuantity())->toBe('7.0000')
            ->and($invoiceItem->remainingCreditableQuantity())->toBe('3.0000');
    });
});

test('supplier credit note can be soft deleted and restored', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = SupplierInvoice::factory()->confirmed()->create();
        $cn = SupplierCreditNote::factory()->draft()->create([
            'supplier_invoice_id' => $invoice->id,
            'partner_id' => $invoice->partner_id,
            'credit_note_number' => 'SCN-DEL',
        ]);

        $cn->delete();
        expect(SupplierCreditNote::where('credit_note_number', 'SCN-DEL')->count())->toBe(0);

        $cn->restore();
        expect(SupplierCreditNote::where('credit_note_number', 'SCN-DEL')->count())->toBe(1);
    });
});
