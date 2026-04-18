<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\CustomerDebitNote;
use App\Models\CustomerDebitNoteItem;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\EuOssAccumulation;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerDebitNoteService;
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

it('parent-attached: inherits vat_scenario, vat_scenario_sub_code, and is_reverse_charge from confirmed parent', function () {
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

        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => $parent->currency_code,
            'total' => '100.00',
            'issued_at' => now()->toDateString(),
        ]);
        CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '20.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '120.00',
        ]);

        app(CustomerDebitNoteService::class)->confirmWithScenario($debitNote);

        $fresh = $debitNote->fresh();
        expect($fresh->status)->toBe(DocumentStatus::Confirmed)
            ->and($fresh->vat_scenario)->toBe(VatScenario::Domestic)
            ->and($fresh->vat_scenario_sub_code)->toBe('default')
            ->and($fresh->is_reverse_charge)->toBeFalse();
    });
});

it('parent-attached: throws DomainException when parent invoice status is Draft', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->draft()->domestic()->create([
            'partner_id' => $partner->id,
        ]);

        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => $parent->currency_code,
            'issued_at' => now()->toDateString(),
        ]);

        // No items needed — service throws before processing items
        expect(fn () => app(CustomerDebitNoteService::class)->confirmWithScenario($debitNote))
            ->toThrow(DomainException::class);

        expect($debitNote->fresh()->status)->toBe(DocumentStatus::Draft);
    });
});

it('parent-attached: throws DomainException when debit note currency does not match parent currency', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->confirmed()->domestic()->create([
            'partner_id' => $partner->id,
            'currency_code' => 'USD',
            'total' => '100.00',
        ]);

        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => 'EUR',
            'issued_at' => now()->toDateString(),
        ]);

        // No items needed — service throws before processing items
        expect(fn () => app(CustomerDebitNoteService::class)->confirmWithScenario($debitNote))
            ->toThrow(DomainException::class);

        expect($debitNote->fresh()->status)->toBe(DocumentStatus::Draft);
    });
});

it('standalone: resolves Domestic scenario for a BG partner and sets status to Confirmed', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');

        $partner = Partner::factory()->customer()->create(['country_code' => 'BG']);
        $vatRate = VatRate::factory()->standard()->create();

        $debitNote = CustomerDebitNote::factory()->standalone()->draft()->create([
            'partner_id' => $partner->id,
            'currency_code' => 'EUR',
            'total' => '100.00',
            'issued_at' => now()->toDateString(),
        ]);
        CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '20.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '120.00',
        ]);

        app(CustomerDebitNoteService::class)->confirmWithScenario($debitNote);

        $fresh = $debitNote->fresh();
        expect($fresh->status)->toBe(DocumentStatus::Confirmed)
            ->and($fresh->vat_scenario)->toBe(VatScenario::Domestic);
    });
});

it('parent-attached: records a positive OSS delta after confirming a debit note against an EU B2C parent', function () {
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
        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $parent->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '500.0000',
            'vat_amount' => '0.00',
            'line_total' => '500.00',
            'line_total_with_vat' => '500.00',
            'sales_order_item_id' => null,
        ]);

        $debitNote = CustomerDebitNote::factory()->draft()->create([
            'customer_invoice_id' => $parent->id,
            'partner_id' => $parent->partner_id,
            'currency_code' => 'EUR',
            'exchange_rate' => '1.000000',
            'total' => '100.00',
            'issued_at' => now()->toDateString(),
        ]);
        CustomerDebitNoteItem::factory()->create([
            'customer_debit_note_id' => $debitNote->id,
            'vat_rate_id' => $vatRate->id,
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'vat_amount' => '0.00',
            'line_total' => '100.00',
            'line_total_with_vat' => '100.00',
        ]);

        app(CustomerDebitNoteService::class)->confirmWithScenario($debitNote);

        $record = EuOssAccumulation::where('country_code', 'DE')
            ->where('year', (int) now()->year)
            ->first();

        expect($record)->not->toBeNull()
            ->and((float) $record->accumulated_amount_eur)->toBeGreaterThan(0.0);
    });
});
