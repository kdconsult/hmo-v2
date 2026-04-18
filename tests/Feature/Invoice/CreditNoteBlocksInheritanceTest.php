<?php

declare(strict_types=1);

use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\Partner;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Services\CustomerCreditNoteService;
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

// F-021: credit note inherits parent's Domestic scenario even when tenant later deregisters
it('F-021: credit note inherits Domestic even when tenant now non-registered', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        VatRate::factory()->standard()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->confirmed()->domestic()->create([
            'partner_id' => Partner::factory()->customer()->create(['country_code' => 'BG'])->id,
            'total' => '100.00',
        ]);

        // Tenant deregisters after parent was confirmed
        $this->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

        $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create([
            'issued_at' => now()->toDateString(),
        ]);

        app(CustomerCreditNoteService::class)->confirmWithScenario($note);

        expect($note->fresh()->vat_scenario)->toBe(VatScenario::Domestic)
            ->and($note->fresh()->is_reverse_charge)->toBeFalse();
    });
});

it('credit note inherits Exempt when parent is Exempt', function () {
    $this->tenant->run(function () {
        $this->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);
        CompanySettings::set('company', 'country_code', 'BG');

        $parent = CustomerInvoice::factory()->confirmed()->exempt()->create([
            'partner_id' => Partner::factory()->customer()->create(['country_code' => 'BG'])->id,
            'total' => '100.00',
        ]);

        $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create([
            'issued_at' => now()->toDateString(),
        ]);

        app(CustomerCreditNoteService::class)->confirmWithScenario($note);

        expect($note->fresh()->vat_scenario)->toBe(VatScenario::Exempt)
            ->and($note->fresh()->vat_scenario_sub_code)->toBe('default');
    });
});

it('credit note inherits EuB2bReverseCharge even when tenant now non-registered', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        VatRate::factory()->zero()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()->create([
            'partner_id' => Partner::factory()->customer()->create(['country_code' => 'DE'])->id,
            'vat_scenario_sub_code' => 'goods',
            'total' => '100.00',
        ]);

        $this->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

        $note = CustomerCreditNote::factory()->draft()->withParent($parent)->create([
            'issued_at' => now()->toDateString(),
        ]);

        app(CustomerCreditNoteService::class)->confirmWithScenario($note);

        expect($note->fresh()->is_reverse_charge)->toBeTrue()
            ->and($note->fresh()->vat_scenario)->toBe(VatScenario::EuB2bReverseCharge)
            ->and($note->fresh()->vat_scenario_sub_code)->toBe('goods');
    });
});

it('credit note items forced to 0% when parent scenario requiresVatRateChange', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        VatRate::factory()->zero()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->confirmed()->euB2bReverseCharge()->create([
            'partner_id' => Partner::factory()->customer()->create(['country_code' => 'DE'])->id,
            'total' => '100.00',
        ]);

        $note = CustomerCreditNote::factory()
            ->draft()
            ->withParent($parent)
            ->withItems(2)
            ->create(['issued_at' => now()->toDateString()]);

        app(CustomerCreditNoteService::class)->confirmWithScenario($note);

        $note->load('items.vatRate');
        foreach ($note->items as $item) {
            expect((float) $item->vatRate->rate)->toBe(0.0);
        }
    });
});
