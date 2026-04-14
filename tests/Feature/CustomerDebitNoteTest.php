<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PricingMode;
use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerDebitNoteService;
use App\Services\TenantOnboardingService;

test('customer debit note can be created with required fields', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();

        $debitNote = CustomerDebitNote::factory()->create([
            'partner_id' => $partner->id,
        ]);

        expect($debitNote->status)->toBe(DocumentStatus::Draft)
            ->and($debitNote->isEditable())->toBeTrue()
            ->and($debitNote->partner_id)->toBe($partner->id);
    });
});

test('isEditable returns false after confirmation', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $draft = CustomerDebitNote::factory()->draft()->create();
        $confirmed = CustomerDebitNote::factory()->confirmed()->create();
        $cancelled = CustomerDebitNote::factory()->cancelled()->create();

        expect($draft->isEditable())->toBeTrue()
            ->and($confirmed->isEditable())->toBeFalse()
            ->and($cancelled->isEditable())->toBeFalse();
    });
});

test('debit note does not require a linked invoice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();

        $debitNote = CustomerDebitNote::factory()->create([
            'partner_id' => $partner->id,
            'customer_invoice_id' => null,
        ]);

        expect($debitNote->customer_invoice_id)->toBeNull()
            ->and($debitNote->status)->toBe(DocumentStatus::Draft);
    });
});

test('recalculateItemTotals computes correct VAT-exclusive totals', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->standard()->create(['rate' => '20.00']);

        $debitNote = CustomerDebitNote::factory()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $item = CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'quantity' => '3.0000',
            'unit_price' => '100.0000',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->setRelation('customerDebitNote', $debitNote);
        $item->setRelation('vatRate', $vatRate);

        app(CustomerDebitNoteService::class)->recalculateItemTotals($item);
        $item->refresh();

        // qty=3, price=100 → base=300; VAT 20% → vat=60, gross=360
        expect((float) $item->line_total)->toBe(300.0)
            ->and((float) $item->vat_amount)->toBe(60.0)
            ->and((float) $item->line_total_with_vat)->toBe(360.0);
    });
});

test('recalculateDocumentTotals aggregates debit note item amounts', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $debitNote = CustomerDebitNote::factory()->create();

        CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'line_total' => '150.00',
            'vat_amount' => '30.00',
            'line_total_with_vat' => '180.00',
        ]);

        CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'line_total' => '250.00',
            'vat_amount' => '50.00',
            'line_total_with_vat' => '300.00',
        ]);

        app(CustomerDebitNoteService::class)->recalculateDocumentTotals($debitNote);
        $debitNote->refresh();

        expect((float) $debitNote->subtotal)->toBe(400.0)
            ->and((float) $debitNote->tax_amount)->toBe(80.0)
            ->and((float) $debitNote->total)->toBe(480.0);
    });
});

test('debit note linked to invoice inherits partner relationship', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();
        $invoice = CustomerInvoice::factory()->create(['partner_id' => $partner->id]);

        $debitNote = CustomerDebitNote::factory()->create([
            'partner_id' => $partner->id,
            'customer_invoice_id' => $invoice->id,
        ]);

        $debitNote->loadMissing('customerInvoice');

        expect($debitNote->customerInvoice)->not->toBeNull()
            ->and($debitNote->customerInvoice->id)->toBe($invoice->id)
            ->and($debitNote->partner_id)->toBe($partner->id);
    });
});
