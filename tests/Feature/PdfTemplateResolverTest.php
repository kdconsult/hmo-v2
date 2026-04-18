<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\PdfTemplateResolver;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'country_code' => 'DE',
        'locale' => 'de',
    ]);

    tenancy()->initialize($this->tenant);
});

afterEach(function () {
    tenancy()->end();
});

it('resolves the bg template when tenant country is BG', function () {
    $this->tenant->update(['country_code' => 'BG', 'locale' => 'bg']);

    expect(app(PdfTemplateResolver::class)->resolve('customer-invoice'))
        ->toBe('pdf.customer-invoice.bg');
});

it('falls back to default when no country-specific template exists', function () {
    $this->tenant->update(['country_code' => 'DE', 'locale' => 'en']);

    expect(app(PdfTemplateResolver::class)->resolve('customer-invoice'))
        ->toBe('pdf.customer-invoice.default');
});

it('forces bg locale when bg template is selected', function () {
    $this->tenant->update(['country_code' => 'BG', 'locale' => 'en']);

    expect(app(PdfTemplateResolver::class)->localeFor('customer-invoice'))->toBe('bg');
});

it('uses tenant UI locale for default template', function () {
    $this->tenant->update(['country_code' => 'DE', 'locale' => 'de']);

    expect(app(PdfTemplateResolver::class)->localeFor('customer-invoice'))->toBe('de');
});
