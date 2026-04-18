<?php

declare(strict_types=1);

use App\Enums\VatScenario;
use App\Models\CompanySettings;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
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

it('standalone debit note + non-registered tenant → Exempt', function () {
    $this->tenant->run(function () {
        $this->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);
        CompanySettings::set('company', 'country_code', 'BG');
        VatRate::factory()->zero()->create(['country_code' => 'BG']);

        $note = CustomerDebitNote::factory()->draft()->standalone()->create([
            'partner_id' => Partner::factory()->customer()->create(['country_code' => 'BG'])->id,
            'issued_at' => now()->toDateString(),
        ]);

        app(CustomerDebitNoteService::class)->confirmWithScenario($note);

        expect($note->fresh()->vat_scenario)->toBe(VatScenario::Exempt)
            ->and($note->fresh()->vat_scenario_sub_code)->toBe('default')
            ->and($note->fresh()->is_reverse_charge)->toBeFalse();
    });
});

it('standalone debit note + registered tenant → fresh determine (Domestic for BG partner)', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        VatRate::factory()->standard()->create(['country_code' => 'BG']);

        $note = CustomerDebitNote::factory()->draft()->standalone()->create([
            'partner_id' => Partner::factory()->customer()->create(['country_code' => 'BG'])->id,
            'issued_at' => now()->toDateString(),
        ]);

        app(CustomerDebitNoteService::class)->confirmWithScenario($note);

        expect($note->fresh()->vat_scenario)->toBe(VatScenario::Domestic);
    });
});

// F-021: parent-attached debit note inherits, blocks do not override
it('F-021: parent-attached debit note inherits Domestic even when tenant deregistered', function () {
    $this->tenant->run(function () {
        CompanySettings::set('company', 'country_code', 'BG');
        VatRate::factory()->standard()->create(['country_code' => 'BG']);

        $parent = CustomerInvoice::factory()->confirmed()->domestic()->create([
            'partner_id' => Partner::factory()->customer()->create(['country_code' => 'BG'])->id,
            'total' => '100.00',
        ]);

        $this->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);

        $note = CustomerDebitNote::factory()->draft()->withParent($parent)->create([
            'issued_at' => now()->toDateString(),
        ]);

        app(CustomerDebitNoteService::class)->confirmWithScenario($note);

        expect($note->fresh()->vat_scenario)->toBe(VatScenario::Domestic);
    });
});

it('standalone debit note items forced to 0% when tenant non-registered', function () {
    $this->tenant->run(function () {
        $this->tenant->update(['is_vat_registered' => false, 'vat_number' => null]);
        CompanySettings::set('company', 'country_code', 'BG');
        VatRate::factory()->zero()->create(['country_code' => 'BG']);

        $note = CustomerDebitNote::factory()
            ->draft()
            ->standalone()
            ->withItems(2)
            ->create([
                'partner_id' => Partner::factory()->customer()->create(['country_code' => 'BG'])->id,
                'issued_at' => now()->toDateString(),
            ]);

        app(CustomerDebitNoteService::class)->confirmWithScenario($note);

        $note->load('items.vatRate');
        foreach ($note->items as $item) {
            expect((float) $item->vatRate->rate)->toBe(0.0);
        }
    });
});
