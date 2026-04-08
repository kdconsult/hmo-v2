<?php

use App\Support\EuCountries;

test('returns 27 EU member states', function () {
    expect(EuCountries::all())->toHaveCount(27);
});

test('codes() returns array of ISO 3166-1 alpha-2 codes', function () {
    $codes = EuCountries::codes();

    expect($codes)->toContain('BG', 'DE', 'FR', 'IT', 'PL')
        ->toHaveCount(27);
});

test('get() returns country data for valid code', function () {
    $country = EuCountries::get('BG');

    expect($country)->not->toBeNull()
        ->and($country['name'])->toBe('Bulgaria')
        ->and($country['currency_code'])->toBe('EUR') // Bulgaria adopted EUR in Jan 2026
        ->and($country['timezone'])->toBe('Europe/Sofia')
        ->and($country['locale'])->toBe('bg_BG')
        ->and($country['vat_prefix'])->toBe('BG');
});

test('get() is case-insensitive', function () {
    expect(EuCountries::get('bg'))->not->toBeNull()
        ->and(EuCountries::get('BG'))->toBe(EuCountries::get('bg'));
});

test('get() returns null for unknown code', function () {
    expect(EuCountries::get('XX'))->toBeNull();
    expect(EuCountries::get('US'))->toBeNull();
});

test('Greece uses EL as VAT prefix, not GR', function () {
    $greece = EuCountries::get('GR');

    expect($greece['vat_prefix'])->toBe('EL')
        ->and($greece['currency_code'])->toBe('EUR');
});

test('non-eurozone countries have their own currency', function () {
    expect(EuCountries::get('DK')['currency_code'])->toBe('DKK');
    expect(EuCountries::get('SE')['currency_code'])->toBe('SEK');
    expect(EuCountries::get('CZ')['currency_code'])->toBe('CZK');
    expect(EuCountries::get('HU')['currency_code'])->toBe('HUF');
    expect(EuCountries::get('PL')['currency_code'])->toBe('PLN');
    expect(EuCountries::get('RO')['currency_code'])->toBe('RON');
});

test('forSelect() returns code => name map', function () {
    $select = EuCountries::forSelect();

    expect($select)->toBeArray()
        ->toHaveCount(27)
        ->toHaveKey('BG', 'Bulgaria')
        ->toHaveKey('DE', 'Germany')
        ->toHaveKey('GR', 'Greece');
});

test('currencyForCountry() returns correct currency', function () {
    expect(EuCountries::currencyForCountry('BG'))->toBe('EUR');
    expect(EuCountries::currencyForCountry('PL'))->toBe('PLN');
    expect(EuCountries::currencyForCountry('XX'))->toBeNull();
});

test('timezoneForCountry() returns correct timezone', function () {
    expect(EuCountries::timezoneForCountry('BG'))->toBe('Europe/Sofia');
    expect(EuCountries::timezoneForCountry('FR'))->toBe('Europe/Paris');
    expect(EuCountries::timezoneForCountry('XX'))->toBeNull();
});

test('localeForCountry() returns correct locale', function () {
    expect(EuCountries::localeForCountry('BG'))->toBe('bg_BG');
    expect(EuCountries::localeForCountry('DE'))->toBe('de_DE');
    expect(EuCountries::localeForCountry('XX'))->toBeNull();
});

test('vatPrefixForCountry() returns correct prefix', function () {
    expect(EuCountries::vatPrefixForCountry('GR'))->toBe('EL');
    expect(EuCountries::vatPrefixForCountry('DE'))->toBe('DE');
    expect(EuCountries::vatPrefixForCountry('XX'))->toBeNull();
});

test('timezones() returns unique timezone strings', function () {
    $timezones = EuCountries::timezones();

    expect($timezones)->toBeArray()
        ->toContain('Europe/Sofia', 'Europe/Berlin', 'Europe/Paris');

    expect(array_unique($timezones))->toHaveCount(count($timezones));
});

test('BGN is not present as any country currency', function () {
    $currencies = array_column(EuCountries::all(), 'currency_code');

    expect($currencies)->not->toContain('BGN');
});
