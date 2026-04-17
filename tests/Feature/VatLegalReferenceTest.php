<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Models\VatLegalReference;
use App\Services\TenantOnboardingService;
use Database\Seeders\VatLegalReferenceSeeder;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($this->tenant, $this->user);
});

test('seeder seeds exactly 16 BG legal references', function () {
    $this->tenant->run(function () {
        expect(VatLegalReference::forCountry('BG')->count())->toBe(16);
    });
});

test('seeder is idempotent — running twice yields 16 rows', function () {
    $this->tenant->run(function () {
        (new VatLegalReferenceSeeder)->run();
        expect(VatLegalReference::forCountry('BG')->count())->toBe(16);
    });
});

test('resolve returns exact match for country + scenario + sub_code', function () {
    $this->tenant->run(function () {
        $ref = VatLegalReference::resolve('BG', 'eu_b2b_reverse_charge', 'goods');
        expect($ref->legal_reference)->toBe('Art. 138 Directive 2006/112/EC');
    });
});

test('resolve falls back to default sub_code when exact not found', function () {
    $this->tenant->run(function () {
        // Exempt has only 'default' — asking for a non-existent sub_code falls through
        $ref = VatLegalReference::resolve('BG', 'exempt', 'nonexistent');
        expect($ref->legal_reference)->toBe('чл. 113, ал. 9 ЗДДС');
    });
});

test('resolve throws DomainException when neither exact nor default exists', function () {
    $this->tenant->run(function () {
        expect(fn () => VatLegalReference::resolve('BG', 'eu_b2b_reverse_charge', 'nonexistent'))
            ->toThrow(DomainException::class);
    });
});

test('listForScenario returns default-first then by sort_order', function () {
    $this->tenant->run(function () {
        $list = VatLegalReference::listForScenario('BG', 'domestic_exempt');
        expect($list->first()->sub_code)->toBe('art_39')
            ->and($list->first()->is_default)->toBeTrue()
            ->and($list->count())->toBe(11);
    });
});

test('translations store both bg and en for domestic_exempt art_39', function () {
    $this->tenant->run(function () {
        $ref = VatLegalReference::resolve('BG', 'domestic_exempt', 'art_39');
        expect($ref->getTranslation('description', 'bg'))->toBe('Доставки, свързани със здравеопазване')
            ->and($ref->getTranslation('description', 'en'))->toBe('Healthcare-related supplies');
    });
});

test('resolver is case-insensitive on country_code', function () {
    $this->tenant->run(function () {
        $ref = VatLegalReference::resolve('bg', 'exempt', 'default');
        expect($ref->country_code)->toBe('BG');
    });
});

test('EU B2B services sub_code resolves to Art. 44 + 196 citation', function () {
    $this->tenant->run(function () {
        $ref = VatLegalReference::resolve('BG', 'eu_b2b_reverse_charge', 'services');
        expect($ref->legal_reference)->toBe('Art. 44 + 196 Directive 2006/112/EC');
    });
});

test('Non-EU services marked outside scope of EU VAT', function () {
    $this->tenant->run(function () {
        $ref = VatLegalReference::resolve('BG', 'non_eu_export', 'services');
        expect($ref->legal_reference)->toContain('outside scope of EU VAT');
    });
});
