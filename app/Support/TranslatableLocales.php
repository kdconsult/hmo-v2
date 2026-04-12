<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CompanySettings;

class TranslatableLocales
{
    /**
     * Returns the locales configured for the current tenant.
     * Falls back to English when tenancy is not initialized (e.g. during artisan commands).
     *
     * Locales are stored as individual keys: localization.locale_en, localization.locale_bg, etc.
     */
    public static function forTenant(): array
    {
        if (! tenancy()->initialized) {
            return ['en'];
        }

        $settings = CompanySettings::getGroup('localization');

        $locales = [];
        foreach (['en', 'bg', 'de', 'fr', 'es', 'ro', 'tr', 'el', 'sr'] as $code) {
            if (! empty($settings["locale_{$code}"])) {
                $locales[] = $code;
            }
        }

        return $locales ?: ['en'];
    }
}
