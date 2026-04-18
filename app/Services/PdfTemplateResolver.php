<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\View;

class PdfTemplateResolver
{
    /**
     * Resolve the Blade view for a given document type and (optionally) explicit country code.
     *
     * View name convention: `pdf.{docType}.{country}` → `resources/views/pdf/{docType}/{country}.blade.php`.
     * Falls back to `pdf.{docType}.default` when the country-specific template is missing.
     */
    public function resolve(string $docType, ?string $countryCode = null): string
    {
        $country = strtolower((string) ($countryCode ?? tenancy()->tenant?->country_code ?? ''));
        $candidate = "pdf.{$docType}.{$country}";

        if ($country !== '' && View::exists($candidate)) {
            return $candidate;
        }

        return "pdf.{$docType}.default";
    }

    /**
     * Locale the template should be rendered in.
     *
     * - Country-specific templates force that country's locale (statutory requirement).
     * - The default template uses the tenant's UI locale, falling back to the app fallback locale.
     */
    public function localeFor(string $docType, ?string $countryCode = null): string
    {
        $country = strtolower((string) ($countryCode ?? tenancy()->tenant?->country_code ?? ''));

        if ($country !== '' && View::exists("pdf.{$docType}.{$country}")) {
            return $country;
        }

        return tenancy()->tenant?->locale
            ?? (string) config('app.fallback_locale', 'en');
    }
}
