<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Tenant-scoped legal-reference lookup keyed by (country_code, vat_scenario, sub_code).
 * Drives the "legal basis" line on invoice / credit-note / debit-note PDFs for any
 * zero-rate, exempt, or reverse-charge scenario.
 *
 * `sub_code` is always populated (sentinel `'default'`) because PostgreSQL unique
 * indexes treat NULL as distinct — a NULL sub_code would allow duplicates.
 */
class VatLegalReference extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'country_code',
        'vat_scenario',
        'sub_code',
        'legal_reference',
        'description',
        'is_default',
        'sort_order',
    ];

    /** @var array<int, string> */
    public array $translatable = ['description'];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeForCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    public function scopeOfScenario(Builder $query, string $scenario): Builder
    {
        return $query->where('vat_scenario', $scenario);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Resolve a single reference. Exact match first, then fallback to sub_code='default'.
     * Throws when neither match exists.
     */
    public static function resolve(string $countryCode, string $scenario, string $subCode = 'default'): self
    {
        $country = strtoupper($countryCode);

        $exact = static::where([
            'country_code' => $country,
            'vat_scenario' => $scenario,
            'sub_code' => $subCode,
        ])->first();

        if ($exact) {
            return $exact;
        }

        if ($subCode !== 'default') {
            $fallback = static::where([
                'country_code' => $country,
                'vat_scenario' => $scenario,
                'sub_code' => 'default',
            ])->first();

            if ($fallback) {
                return $fallback;
            }
        }

        throw new DomainException(
            "No VAT legal reference for country={$country}, scenario={$scenario}, sub_code={$subCode}"
        );
    }

    /**
     * List references for a scenario — populates Select dropdowns.
     * Ordered by is_default DESC (defaults first), then sort_order.
     *
     * @return Collection<int, self>
     */
    public static function listForScenario(string $countryCode, string $scenario): Collection
    {
        return static::forCountry($countryCode)
            ->ofScenario($scenario)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->get();
    }
}
