<?php

namespace App\Filament\Resources\Partners\Concerns;

use App\Enums\VatStatus;
use App\Services\ViesValidationService;
use App\Support\EuCountries;
use Filament\Notifications\Notification;

trait HandlesPartnerViesCheck
{
    public function handleViesCheck(): void
    {
        $countryCode = (string) (data_get($this->data ?? [], 'country_code') ?: 'BG');
        $lookupValue = trim((string) data_get($this->data ?? [], 'vat_lookup', ''));

        if (blank($lookupValue)) {
            Notification::make()->warning()->title('Enter a VAT number first')->send();

            return;
        }

        $prefix = EuCountries::vatPrefixForCountry($countryCode) ?? $countryCode;
        $fullVat = strtoupper($prefix.$lookupValue);

        $regex = EuCountries::vatNumberRegex($countryCode);
        if ($regex && ! preg_match($regex, $fullVat)) {
            Notification::make()->danger()
                ->title('Invalid VAT number format')
                ->body('Expected format: '.(EuCountries::vatNumberExample($countryCode) ?? 'unknown'))
                ->send();

            return;
        }

        $result = app(ViesValidationService::class)->validate($prefix, $lookupValue);

        if (! $result['available']) {
            // VIES unavailable → pending (NOT reset toggle — key delta from Area 1)
            data_set($this->data, 'vat_number', null);
            data_set($this->data, 'vat_status', VatStatus::Pending->value);
            Notification::make()->warning()
                ->title('VIES service is unreachable')
                ->body('Partner will be saved as pending. You can re-verify later.')
                ->send();

            return;
        }

        if (! $result['valid']) {
            data_set($this->data, 'is_vat_registered', false);
            data_set($this->data, 'vat_number', null);
            data_set($this->data, 'vat_status', VatStatus::NotRegistered->value);
            data_set($this->data, 'vat_lookup', '');
            Notification::make()->warning()
                ->title('VAT number not found in VIES')
                ->body("Checked: {$fullVat}")
                ->send();

            return;
        }

        $confirmedVat = strtoupper($prefix.($result['vat_number'] ?? $lookupValue));
        data_set($this->data, 'vat_number', $confirmedVat);
        data_set($this->data, 'vat_status', VatStatus::Confirmed->value);
        data_set($this->data, 'is_vat_registered', true);

        if (filled($result['name'])) {
            data_set($this->data, 'name', $result['name']);
        }

        if (filled($result['address'] ?? null)) {
            data_set($this->data, 'vies_raw_address', $result['address']);
        }

        Notification::make()->success()
            ->title('VAT registration confirmed')
            ->body($confirmedVat)
            ->send();
    }

    public function resetVatState(): void
    {
        if (! isset($this->data)) {
            return;
        }

        data_set($this->data, 'is_vat_registered', false);
        data_set($this->data, 'vat_number', null);
        data_set($this->data, 'vat_lookup', '');
        data_set($this->data, 'vat_status', VatStatus::NotRegistered->value);
    }

    public function vatCountryPrefix(): string
    {
        $countryCode = (string) (data_get($this->data ?? [], 'country_code') ?: 'BG');

        return EuCountries::vatPrefixForCountry($countryCode) ?? $countryCode;
    }

    public function vatLookupHelperText(): ?string
    {
        $countryCode = (string) (data_get($this->data ?? [], 'country_code') ?: 'BG');
        $example = EuCountries::vatNumberExample($countryCode);

        return $example ? "Format: {$example}" : null;
    }
}
