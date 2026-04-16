<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Static reference data for EU member states.
 *
 * @phpstan-type CountryData array{name: string, vat_prefix: string, currency_code: string, timezone: string, locale: string}
 * @phpstan-type VatFormat array{regex: string, example: string}
 */
class EuCountries
{
    /**
     * VAT number formats keyed by ISO country code.
     * `regex` matches the full VAT number (including country/VAT prefix).
     * `example` is shown as helper text in the form.
     *
     * @var array<string, VatFormat>
     */
    private static array $vatFormats = [
        'AT' => ['regex' => 'ATU\d{8}',                          'example' => 'ATU12345678'],
        'BE' => ['regex' => 'BE\d{10}',                          'example' => 'BE1234567890'],
        'BG' => ['regex' => 'BG\d{9,10}',                        'example' => 'BG123456789'],
        'HR' => ['regex' => 'HR\d{11}',                          'example' => 'HR12345678901'],
        'CY' => ['regex' => 'CY\d{8}[A-Z]',                     'example' => 'CY12345678A'],
        'CZ' => ['regex' => 'CZ\d{8,10}',                        'example' => 'CZ12345678'],
        'DK' => ['regex' => 'DK\d{8}',                           'example' => 'DK12345678'],
        'EE' => ['regex' => 'EE\d{9}',                           'example' => 'EE123456789'],
        'FI' => ['regex' => 'FI\d{8}',                           'example' => 'FI12345678'],
        'FR' => ['regex' => 'FR[0-9A-HJ-NP-Z]{2}\d{9}',         'example' => 'FR12345678901'],
        'DE' => ['regex' => 'DE\d{9}',                           'example' => 'DE123456789'],
        'GR' => ['regex' => 'EL\d{9}',                           'example' => 'EL123456789'],   // VIES prefix: EL
        'HU' => ['regex' => 'HU\d{8}',                           'example' => 'HU12345678'],
        'IE' => ['regex' => 'IE\d[0-9A-Z+*]\d{5}[A-Z]{1,2}',   'example' => 'IE1234567X'],
        'IT' => ['regex' => 'IT\d{11}',                          'example' => 'IT12345678901'],
        'LV' => ['regex' => 'LV\d{11}',                          'example' => 'LV12345678901'],
        'LT' => ['regex' => 'LT(\d{9}|\d{12})',                  'example' => 'LT123456789'],
        'LU' => ['regex' => 'LU\d{8}',                           'example' => 'LU12345678'],
        'MT' => ['regex' => 'MT\d{8}',                           'example' => 'MT12345678'],
        'NL' => ['regex' => 'NL\d{9}B\d{2}',                    'example' => 'NL123456789B01'],
        'PL' => ['regex' => 'PL\d{10}',                          'example' => 'PL1234567890'],
        'PT' => ['regex' => 'PT\d{9}',                           'example' => 'PT123456789'],
        'RO' => ['regex' => 'RO\d{2,10}',                        'example' => 'RO12345678'],
        'SK' => ['regex' => 'SK\d{10}',                          'example' => 'SK1234567890'],
        'SI' => ['regex' => 'SI\d{8}',                           'example' => 'SI12345678'],
        'ES' => ['regex' => 'ES[A-Z0-9]\d{7}[A-Z0-9]',          'example' => 'ESX12345678'],
        'SE' => ['regex' => 'SE\d{12}',                          'example' => 'SE123456789012'],
    ];

    /** @var array<string, CountryData> */
    private static array $countries = [
        'AT' => ['name' => 'Austria', 'vat_prefix' => 'AT', 'currency_code' => 'EUR', 'timezone' => 'Europe/Vienna', 'locale' => 'de_AT'],
        'BE' => ['name' => 'Belgium', 'vat_prefix' => 'BE', 'currency_code' => 'EUR', 'timezone' => 'Europe/Brussels', 'locale' => 'fr_BE'],
        'BG' => ['name' => 'Bulgaria', 'vat_prefix' => 'BG', 'currency_code' => 'EUR', 'timezone' => 'Europe/Sofia', 'locale' => 'bg_BG'],
        'CY' => ['name' => 'Cyprus', 'vat_prefix' => 'CY', 'currency_code' => 'EUR', 'timezone' => 'Asia/Nicosia', 'locale' => 'el_CY'],
        'CZ' => ['name' => 'Czech Republic', 'vat_prefix' => 'CZ', 'currency_code' => 'CZK', 'timezone' => 'Europe/Prague', 'locale' => 'cs_CZ'],
        'DE' => ['name' => 'Germany', 'vat_prefix' => 'DE', 'currency_code' => 'EUR', 'timezone' => 'Europe/Berlin', 'locale' => 'de_DE'],
        'DK' => ['name' => 'Denmark', 'vat_prefix' => 'DK', 'currency_code' => 'DKK', 'timezone' => 'Europe/Copenhagen', 'locale' => 'da_DK'],
        'EE' => ['name' => 'Estonia', 'vat_prefix' => 'EE', 'currency_code' => 'EUR', 'timezone' => 'Europe/Tallinn', 'locale' => 'et_EE'],
        'ES' => ['name' => 'Spain', 'vat_prefix' => 'ES', 'currency_code' => 'EUR', 'timezone' => 'Europe/Madrid', 'locale' => 'es_ES'],
        'FI' => ['name' => 'Finland', 'vat_prefix' => 'FI', 'currency_code' => 'EUR', 'timezone' => 'Europe/Helsinki', 'locale' => 'fi_FI'],
        'FR' => ['name' => 'France', 'vat_prefix' => 'FR', 'currency_code' => 'EUR', 'timezone' => 'Europe/Paris', 'locale' => 'fr_FR'],
        'GR' => ['name' => 'Greece', 'vat_prefix' => 'EL', 'currency_code' => 'EUR', 'timezone' => 'Europe/Athens', 'locale' => 'el_GR'],
        'HR' => ['name' => 'Croatia', 'vat_prefix' => 'HR', 'currency_code' => 'EUR', 'timezone' => 'Europe/Zagreb', 'locale' => 'hr_HR'],
        'HU' => ['name' => 'Hungary', 'vat_prefix' => 'HU', 'currency_code' => 'HUF', 'timezone' => 'Europe/Budapest', 'locale' => 'hu_HU'],
        'IE' => ['name' => 'Ireland', 'vat_prefix' => 'IE', 'currency_code' => 'EUR', 'timezone' => 'Europe/Dublin', 'locale' => 'en_IE'],
        'IT' => ['name' => 'Italy', 'vat_prefix' => 'IT', 'currency_code' => 'EUR', 'timezone' => 'Europe/Rome', 'locale' => 'it_IT'],
        'LT' => ['name' => 'Lithuania', 'vat_prefix' => 'LT', 'currency_code' => 'EUR', 'timezone' => 'Europe/Vilnius', 'locale' => 'lt_LT'],
        'LU' => ['name' => 'Luxembourg', 'vat_prefix' => 'LU', 'currency_code' => 'EUR', 'timezone' => 'Europe/Luxembourg', 'locale' => 'fr_LU'],
        'LV' => ['name' => 'Latvia', 'vat_prefix' => 'LV', 'currency_code' => 'EUR', 'timezone' => 'Europe/Riga', 'locale' => 'lv_LV'],
        'MT' => ['name' => 'Malta', 'vat_prefix' => 'MT', 'currency_code' => 'EUR', 'timezone' => 'Europe/Malta', 'locale' => 'mt_MT'],
        'NL' => ['name' => 'Netherlands', 'vat_prefix' => 'NL', 'currency_code' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'locale' => 'nl_NL'],
        'PL' => ['name' => 'Poland', 'vat_prefix' => 'PL', 'currency_code' => 'PLN', 'timezone' => 'Europe/Warsaw', 'locale' => 'pl_PL'],
        'PT' => ['name' => 'Portugal', 'vat_prefix' => 'PT', 'currency_code' => 'EUR', 'timezone' => 'Europe/Lisbon', 'locale' => 'pt_PT'],
        'RO' => ['name' => 'Romania', 'vat_prefix' => 'RO', 'currency_code' => 'RON', 'timezone' => 'Europe/Bucharest', 'locale' => 'ro_RO'],
        'SE' => ['name' => 'Sweden', 'vat_prefix' => 'SE', 'currency_code' => 'SEK', 'timezone' => 'Europe/Stockholm', 'locale' => 'sv_SE'],
        'SI' => ['name' => 'Slovenia', 'vat_prefix' => 'SI', 'currency_code' => 'EUR', 'timezone' => 'Europe/Ljubljana', 'locale' => 'sl_SI'],
        'SK' => ['name' => 'Slovakia', 'vat_prefix' => 'SK', 'currency_code' => 'EUR', 'timezone' => 'Europe/Bratislava', 'locale' => 'sk_SK'],
    ];

    /** @return array<string, CountryData> */
    public static function all(): array
    {
        return self::$countries;
    }

    /** @return string[] */
    public static function codes(): array
    {
        return array_keys(self::$countries);
    }

    /** @return CountryData|null */
    public static function get(string $code): ?array
    {
        return self::$countries[strtoupper($code)] ?? null;
    }

    /** @return array<string, string> code => name */
    public static function forSelect(): array
    {
        return array_map(fn (array $c) => $c['name'], self::$countries);
    }

    /** @return string[] unique timezones */
    public static function timezones(): array
    {
        return array_unique(array_column(self::$countries, 'timezone'));
    }

    public static function currencyForCountry(string $code): ?string
    {
        return self::get($code)['currency_code'] ?? null;
    }

    public static function timezoneForCountry(string $code): ?string
    {
        return self::get($code)['timezone'] ?? null;
    }

    public static function localeForCountry(string $code): ?string
    {
        return self::get($code)['locale'] ?? null;
    }

    public static function vatPrefixForCountry(string $code): ?string
    {
        return self::get($code)['vat_prefix'] ?? null;
    }

    public static function isEuCountry(string $code): bool
    {
        return in_array($code, static::codes(), true);
    }

    /**
     * Full regex (with delimiters) matching a valid VAT number for the given country.
     * Returns null for unknown country codes.
     */
    public static function vatNumberRegex(string $code): ?string
    {
        $format = self::$vatFormats[strtoupper($code)] ?? null;

        return $format ? '/^'.$format['regex'].'$/i' : null;
    }

    /**
     * An example VAT number for the given country, suitable for helper text.
     */
    public static function vatNumberExample(string $code): ?string
    {
        return self::$vatFormats[strtoupper($code)]['example'] ?? null;
    }

    /**
     * Extracts the number to pass to the VIES API from a raw EIK.
     *
     * In Bulgaria, legal-entity EIKs are 9 digits and individuals are 10.
     * Subdivisions/branches append 2 extra digits (→ 11 digits), but they share
     * their parent's 9-digit VAT number. This method strips the subdivision suffix
     * so the VIES lookup hits the correct parent registration.
     */
    public static function extractMainVatNumber(string $countryCode, string $eik): string
    {
        return match (strtoupper($countryCode)) {
            'BG' => strlen($eik) > 10 ? substr($eik, 0, 9) : $eik,
            default => $eik,
        };
    }
}
