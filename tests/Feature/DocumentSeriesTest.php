<?php

use App\Enums\DocumentType;
use App\Models\DocumentSeries;
use App\Models\Tenant;

function createTestTenant(): Tenant
{
    return Tenant::create([
        'name' => 'Test Tenant',
        'slug' => 'test-'.uniqid(),
        'email' => 'test@test.com',
        'country_code' => 'BG',
        'locale' => 'bg',
        'timezone' => 'Europe/Sofia',
        'default_currency_code' => 'BGN',
    ]);
}

test('document series generates unique sequential numbers', function () {
    $tenant = createTestTenant();
    tenancy()->initialize($tenant);

    $series = DocumentSeries::create([
        'document_type' => DocumentType::Invoice->value,
        'name' => 'Test Series',
        'prefix' => 'INV',
        'separator' => '-',
        'include_year' => true,
        'year_format' => 'Y',
        'padding' => 5,
        'next_number' => 1,
        'reset_yearly' => true,
        'is_default' => true,
        'is_active' => true,
    ]);

    $number1 = $series->generateNumber();
    $number2 = $series->generateNumber();
    $number3 = $series->generateNumber();

    expect($number1)->not->toBe($number2)
        ->and($number2)->not->toBe($number3);

    $year = date('Y');
    expect($number1)->toContain('INV')
        ->and($number1)->toContain($year)
        ->and($number1)->toContain('00001');

    tenancy()->end();
});

test('document series increments next_number after generation', function () {
    $tenant = createTestTenant();
    tenancy()->initialize($tenant);

    $series = DocumentSeries::create([
        'document_type' => DocumentType::Invoice->value,
        'name' => 'Increment Test',
        'prefix' => 'TEST',
        'separator' => '-',
        'include_year' => false,
        'year_format' => 'Y',
        'padding' => 3,
        'next_number' => 10,
        'reset_yearly' => false,
        'is_default' => false,
        'is_active' => true,
    ]);

    $series->generateNumber();

    $series->refresh();
    expect($series->next_number)->toBe(11);

    tenancy()->end();
});
