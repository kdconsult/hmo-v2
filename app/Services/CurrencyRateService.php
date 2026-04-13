<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class CurrencyRateService
{
    private ?string $baseCurrencyCode = null;

    /**
     * Get the default (base) currency code from the Currency model.
     * Cached per request to avoid repeated queries.
     */
    public function getBaseCurrencyCode(): string
    {
        return $this->baseCurrencyCode ??= Currency::where('is_default', true)->value('code') ?? 'EUR';
    }

    /**
     * Resolve the exchange rate for a currency code on a given date.
     *
     * Returns '1.000000' when $currencyCode equals the base currency.
     * Falls back to the most recent rate before $date if no exact match.
     * Returns null if no rate has ever been recorded for this currency.
     */
    public function getRate(string $currencyCode, ?Carbon $date = null): ?string
    {
        $date ??= today();

        if ($currencyCode === $this->getBaseCurrencyCode()) {
            return '1.000000';
        }

        $currencyId = Currency::where('code', $currencyCode)->value('id');

        if (! $currencyId) {
            return null;
        }

        $baseCurrency = $this->getBaseCurrencyCode();

        // Exact match for the requested date
        $exact = ExchangeRate::where('currency_id', $currencyId)
            ->where('base_currency_code', $baseCurrency)
            ->where('date', $date->toDateString())
            ->value('rate');

        if ($exact !== null) {
            return number_format((float) $exact, 6, '.', '');
        }

        // Fallback: most recent rate on or before the requested date
        $fallback = ExchangeRate::where('currency_id', $currencyId)
            ->where('base_currency_code', $baseCurrency)
            ->where('date', '<=', $date->toDateString())
            ->orderByDesc('date')
            ->value('rate');

        if ($fallback !== null) {
            return number_format((float) $fallback, 6, '.', '');
        }

        return null;
    }

    /**
     * Filament afterStateUpdated closure for currency_code select fields.
     * Call when the user changes currency — fetches rate for the document date.
     */
    public static function makeAfterCurrencyChanged(string $dateField): Closure
    {
        return function (Set $set, Get $get, ?string $state) use ($dateField): void {
            if (! $state) {
                return;
            }

            $dateValue = $get($dateField);
            $date = $dateValue ? Carbon::parse($dateValue) : today();

            $rate = app(self::class)->getRate($state, $date);

            if ($rate !== null) {
                $set('exchange_rate', $rate);
            }
        };
    }

    /**
     * Filament afterStateUpdated closure for document date picker fields.
     * Call when the user changes the document date — re-fetches rate for the new date.
     */
    public static function makeAfterDateChanged(string $currencyField = 'currency_code'): Closure
    {
        return function (Set $set, Get $get, ?string $state) use ($currencyField): void {
            $currencyCode = $get($currencyField);

            if (! $currencyCode || ! $state) {
                return;
            }

            $rate = app(self::class)->getRate($currencyCode, Carbon::parse($state));

            if ($rate !== null) {
                $set('exchange_rate', $rate);
            }
        };
    }
}
