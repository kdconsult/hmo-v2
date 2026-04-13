<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
     * Clears exchange_rate and warns the user when no rate is found.
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
            } else {
                $set('exchange_rate', null);
                Notification::make()
                    ->title('No exchange rate found')
                    ->body('No rate found for this currency. Please enter the exchange rate manually, then click the bookmark icon to save it for reuse.')
                    ->warning()
                    ->persistent()
                    ->send();
            }
        };
    }

    /**
     * Filament afterStateUpdated closure for document date picker fields.
     * Call when the user changes the document date — re-fetches rate for the new date.
     * Clears exchange_rate and warns the user when no rate is found.
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
            } else {
                // Only warn if currency is not base (base always returns '1.000000')
                $baseCurrency = app(self::class)->getBaseCurrencyCode();
                if ($currencyCode !== $baseCurrency) {
                    $set('exchange_rate', null);
                    Notification::make()
                        ->title('No exchange rate found')
                        ->body('No rate found for this currency on the selected date. Please enter the exchange rate manually, then click the bookmark icon to save it for reuse.')
                        ->warning()
                        ->persistent()
                        ->send();
                }
            }
        };
    }

    /**
     * Filament suffix Action for exchange_rate TextInput fields.
     * Saves the entered rate to the ExchangeRate table for the currency + date combination.
     * Only visible when a non-base currency is selected and a rate value is present.
     */
    public static function makeSaveRateAction(string $dateField): Action
    {
        return Action::make('save_exchange_rate')
            ->label('Save rate')
            ->icon('heroicon-o-bookmark')
            ->tooltip('Save this rate to the exchange rate table for reuse')
            ->size('sm')
            ->color('gray')
            ->visible(function (Get $get): bool {
                $currencyCode = $get('currency_code');

                if (! $currencyCode) {
                    return false;
                }

                return $currencyCode !== app(self::class)->getBaseCurrencyCode();
            })
            ->action(function (Get $get) use ($dateField): void {
                $currencyCode = $get('currency_code');
                $rate = $get('exchange_rate');

                if (! $rate) {
                    Notification::make()->title('Enter a rate first')->warning()->send();

                    return;
                }

                $dateValue = $get($dateField);
                $date = $dateValue ? Carbon::parse($dateValue)->toDateString() : today()->toDateString();

                $service = app(self::class);
                $currencyId = Currency::where('code', $currencyCode)->value('id');

                if (! $currencyId) {
                    Notification::make()->title('Currency not found')->danger()->send();

                    return;
                }

                ExchangeRate::updateOrCreate(
                    [
                        'currency_id' => $currencyId,
                        'base_currency_code' => $service->getBaseCurrencyCode(),
                        'date' => $date,
                    ],
                    [
                        'rate' => $rate,
                        'source' => 'manual',
                    ]
                );

                Notification::make()
                    ->title('Exchange rate saved')
                    ->body("Rate {$rate} saved for {$currencyCode} on {$date}.")
                    ->success()
                    ->send();
            });
    }
}
