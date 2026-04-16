<?php

namespace App\Services;

use App\Models\CompanySettings;
use App\Support\EuCountries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ViesValidationService
{
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * Validate an EU VAT number via VIES using checkVatApprox, which returns a requestIdentifier
     * for audit trail purposes (required for Area 3 invoice confirmation).
     *
     * @param  bool  $fresh  When true, bypass the 24h cache and always call VIES live.
     *                       Pass true at invoice confirmation time.
     * @return array{available: bool, valid: bool, name: string|null, address: string|null, country_code: string, vat_number: string, request_id: string|null}
     */
    public function validate(string $countryCode, string $vatNumber, bool $fresh = false): array
    {
        $cacheKey = "vies_validation_{$countryCode}_{$vatNumber}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        $result = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($countryCode, $vatNumber) {
            return $this->callVies($countryCode, $vatNumber);
        });

        // Transient failures (VIES down/timeout) must not be cached — next call should retry.
        if (! $result['available']) {
            Cache::forget($cacheKey);
        }

        return $result;
    }

    /**
     * Call VIES via checkVatApprox.
     *
     * checkVatApprox returns a requestIdentifier that serves as the audit reference.
     * It requires the requester's country code and VAT number (the tenant's own registration).
     *
     * Note: Both checkVat and checkVatApprox are defined in the same WSDL
     * (checkVatService.wsdl). Verify this holds against the live endpoint if SOAP faults occur.
     *
     * @return array{available: bool, valid: bool, name: string|null, address: string|null, country_code: string, vat_number: string, request_id: string|null}
     */
    /**
     * Create the SOAP client. Extracted as a protected method so tests can override it
     * without hitting the live VIES endpoint.
     */
    protected function makeSoapClient(): \SoapClient
    {
        return new \SoapClient(
            'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl',
            ['exceptions' => true, 'connection_timeout' => 10]
        );
    }

    private function callVies(string $countryCode, string $vatNumber): array
    {
        try {
            $client = $this->makeSoapClient();

            $requesterCountry = strtoupper(CompanySettings::get('company', 'country_code') ?? '');

            // Strip the country prefix from the tenant's stored VAT number.
            // vat_number is stored as "BG123456789" (prefix + number), but checkVatApprox
            // expects requesterCountryCode = "BG" and requesterVatNumber = "123456789" separately.
            $storedRequesterVat = tenancy()->tenant?->vat_number ?? '';
            if ($requesterCountry && $storedRequesterVat) {
                $requesterPrefix = EuCountries::vatPrefixForCountry($requesterCountry) ?? $requesterCountry;
                $requesterVatNumber = (strlen($requesterPrefix) > 0 && str_starts_with(strtoupper($storedRequesterVat), strtoupper($requesterPrefix)))
                    ? substr($storedRequesterVat, strlen($requesterPrefix))
                    : $storedRequesterVat;
                $requesterVatNumber = preg_replace('/[^0-9A-Za-z]/', '', $requesterVatNumber);
            } else {
                // Pass empty strings for both when requester info is unavailable.
                // Providing a country code without a VAT number also triggers INVALID_REQUESTER_INFO.
                $requesterCountry = '';
                $requesterVatNumber = '';
            }

            $result = $client->checkVatApprox([
                'countryCode' => strtoupper($countryCode),
                'vatNumber' => preg_replace('/[^0-9A-Za-z]/', '', $vatNumber),
                'requesterCountryCode' => $requesterCountry,
                'requesterVatNumber' => $requesterVatNumber,
            ]);

            $name = $result->traderName ?? null;
            $address = $result->traderAddress ?? null;

            return [
                'available' => true,
                'valid' => (bool) $result->valid,
                'name' => ($name && $name !== '---') ? $name : null,
                'address' => ($address && $address !== '---') ? $address : null,
                'country_code' => $countryCode,
                'vat_number' => $vatNumber,
                'request_id' => $result->requestIdentifier ?? null,
            ];
        } catch (\Exception $e) {
            Log::warning('VIES validation failed', [
                'country_code' => $countryCode,
                'vat_number' => $vatNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'valid' => false,
                'name' => null,
                'address' => null,
                'country_code' => $countryCode,
                'vat_number' => $vatNumber,
                'request_id' => null,
            ];
        }
    }
}
