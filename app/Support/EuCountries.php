<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Static reference data for EU member states.
 *
 * @phpstan-type CountryData array{name: string, vat_prefix: string, currency_code: string, timezone: string, locale: string}
 */
class EuCountries
{
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
}
