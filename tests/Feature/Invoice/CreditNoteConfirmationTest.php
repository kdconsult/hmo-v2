<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteItem;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerCreditNoteService;
use App\Services\EuOssService;
use App\Services\TenantOnboardingService;

beforeEach(function () {
    $this->tenant = Tenant::factory()->vatRegistered()->create([
        'country_code' => 'BG',
        'locale' => 'bg',
    ]);
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

afterEach(function () {
    tenancy()->end();
});

it('inherits vat_scenario, vat_scenario_sub_code, and is_reverse_charge from confirmed parent invoice', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $vatRate = VatRate::factory()->standard()->create();

        $parent = CustomerInvoice::factory()->confirmed()->domestic()->create([
            'partner_id' => $partner->id,
            'total' => '100.00',
        ]);
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $parent->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '20.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '120.00',
            'sales_order_item_id' => null,
        ]);

        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => $parent->currency_code,
            'total' => '100.00',
            'issued_at' => now()->toDateString(),
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => CustomerInvoiceItem::where('customer_invoice_id', $parent->id)->first()->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '20.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '120.00',
        ]);

        app(CustomerCreditNoteService::class)->confirmWithScenario($creditNote);

        $fresh = $creditNote->fresh();
        expect($fresh->status)->toBe(DocumentStatus::Confirmed)
            ->and($fresh->vat_scenario)->toBe(VatScenario::Domestic)
            ->and($fresh->vat_scenario_sub_code)->toBe('default')
            ->and($fresh->is_reverse_charge)->toBeFalse();
    });
});

it('throws DomainException when parent invoice status is Draft', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->draft()->domestic()->create([
            'partner_id' => $partner->id,
        ]);

        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => $parent->currency_code,
            'issued_at' => now()->toDateString(),
        ]);

        // No items needed — service throws before processing items
        expect(fn () => app(CustomerCreditNoteService::class)->confirmWithScenario($creditNote))
            ->toThrow(DomainException::class);

        expect($creditNote->fresh()->status)->toBe(DocumentStatus::Draft);
    });
});

it('throws DomainException when credit note currency does not match parent currency', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->confirmed()->domestic()->create([
            'partner_id' => $partner->id,
            'currency_code' => 'USD',
            'total' => '100.00',
        ]);

        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => 'EUR',
            'issued_at' => now()->toDateString(),
        ]);

        // No items needed — service throws before processing items
        expect(fn () => app(CustomerCreditNoteService::class)->confirmWithScenario($creditNote))
            ->toThrow(DomainException::class);

        expect($creditNote->fresh()->status)->toBe(DocumentStatus::Draft);
    });
});

it('does not block confirmation when issued_at is 10 days after triggering_event_date', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $vatRate = VatRate::factory()->standard()->create();

        $parent = CustomerInvoice::factory()->confirmed()->domestic()->create([
            'partner_id' => $partner->id,
            'total' => '100.00',
        ]);
        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $parent->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '20.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '120.00',
            'sales_order_item_id' => null,
        ]);

        $triggerDate = now()->subDays(10)->toDateString();
        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => $parent->currency_code,
            'total' => '100.00',
            'issued_at' => now()->toDateString(),
            'triggering_event_date' => $triggerDate,
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '20.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '120.00',
        ]);

        app(CustomerCreditNoteService::class)->confirmWithScenario($creditNote);

        expect($creditNote->fresh()->status)->toBe(DocumentStatus::Confirmed);
    });
});

it('records a negative OSS delta after confirming a credit note against an EU B2C parent', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create([
            'country_code' => 'DE',
            'vat_number' => '',
        ]);
        $vatRate = VatRate::factory()->zero()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->confirmed()->euB2cOverThreshold()->create([
            'partner_id' => $partner->id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '500.00',
            'issued_at' => now()->toDateString(),
        ]);
        $parent->load('partner');

        // Simulate original invoice accumulation
        app(EuOssService::class)->accumulate($parent);

        $originalAmount = (float) EuOssAccumulation::where('country_code', 'DE')
            ->where('year', (int) now()->year)
            ->first()
            ->accumulated_amount_eur;

        $invoiceItem = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $parent->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '0.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '100.00',
            'sales_order_item_id' => null,
        ]);

        $creditNote = CustomerCreditNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '100.00',
            'issued_at' => now()->toDateString(),
        ]);
        CustomerCreditNoteItem::factory()->create([
            'customer_credit_note_id' => $creditNote->id,
            'customer_invoice_item_id' => $invoiceItem->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '0.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '100.00',
        ]);

        app(CustomerCreditNoteService::class)->confirmWithScenario($creditNote);

        $afterAmount = (float) EuOssAccumulation::where('country_code', 'DE')
            ->where('year', (int) now()->year)
            ->first()
            ->accumulated_amount_eur;

        expect($afterAmount)->toBeLessThan($originalAmount);
    });
});
