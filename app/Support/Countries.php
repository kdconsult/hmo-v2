<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Static list of countries used in Filament Select dropdowns where a
 * non-EU partner can legitimately be picked (customer invoices, partner form).
 *
 * EU 27 is authoritative in EuCountries (owns VAT regex, VIES prefix, currency).
 * This helper composes EU 27 + a curated set of non-EU trading partners that
 * typical EU SMEs actually invoice.
 *
 * Tenants add more entries here as they need them — intentionally kept short.
 */
class Countries
{
    /**
     * Non-EU countries commonly traded with by EU SMEs.
     *
     * @var array<string, string>
     */
    private static array $nonEu = [
        'AL' => 'Albania',
        'AU' => 'Australia',
        'BA' => 'Bosnia and Herzegovina',
        'CA' => 'Canada',
        'CH' => 'Switzerland',
        'GB' => 'United Kingdom',
        'JP' => 'Japan',
        'MD' => 'Moldova',
        'ME' => 'Montenegro',
        'MK' => 'North Macedonia',
        'NO' => 'Norway',
        'NZ' => 'New Zealand',
        'RS' => 'Serbia',
        'TR' => 'Turkey',
        'UA' => 'Ukraine',
        'US' => 'United States',
    ];

    /**
     * All countries offered in the Partner / Customer dropdown.
     * EU 27 merged with the curated non-EU list, sorted alphabetically by name.
     *
     * @return array<string, string> country code => display name
     */
    public static function forSelect(): array
    {
        $merged = EuCountries::forSelect() + self::$nonEu;
        asort($merged);

        return $merged;
    }

    /**
     * ISO code => English name, without sorting (useful for tests).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return EuCountries::forSelect() + self::$nonEu;
    }

    public static function isKnown(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::all());
    }

    public static function nameFor(string $code): ?string
    {
        return self::all()[strtoupper($code)] ?? null;
    }
}
