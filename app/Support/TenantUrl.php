<?php

namespace App\Support;

/**
 * Builds tenant and central URLs using the scheme from config('app.url'),
 * avoiding hardcoded http:// in code that runs in production over HTTPS.
 */
class TenantUrl
{
    /**
     * Build a URL for a tenant subdomain.
     *
     * @param  string  $slug  The tenant subdomain slug.
     * @param  string  $path  Optional path (without leading slash).
     */
    public static function to(string $slug, string $path = ''): string
    {
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?? 'http';
        $domain = config('app.domain');
        $base = "{$scheme}://{$slug}.{$domain}";

        return $path !== '' ? "{$base}/{$path}" : $base;
    }

    /**
     * Build a URL on the central (landlord) domain.
     *
     * @param  string  $path  Optional path (without leading slash).
     */
    public static function central(string $path = ''): string
    {
        $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?? 'http';
        $domain = config('app.domain');
        $base = "{$scheme}://{$domain}";

        return $path !== '' ? "{$base}/{$path}" : $base;
    }
}
