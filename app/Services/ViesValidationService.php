<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ViesValidationService
{
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * Validate an EU VAT number via VIES.
     *
     * `available` distinguishes a definitive VIES "invalid" from a service outage:
     *   - available=true, valid=true  → VIES confirmed valid
     *   - available=true, valid=false → VIES explicitly says invalid
     *   - available=false, valid=false → VIES unreachable / timeout / SOAP error
     *
     * @return array{available: bool, valid: bool, name: string|null, address: string|null, country_code: string, vat_number: string}
     */
    public function validate(string $countryCode, string $vatNumber): array
    {
        $cacheKey = "vies_validation_{$countryCode}_{$vatNumber}";

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
     * @return array{available: bool, valid: bool, name: string|null, address: string|null, country_code: string, vat_number: string}
     */
    private function callVies(string $countryCode, string $vatNumber): array
    {
        try {
            $client = new \SoapClient(
                'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl',
                ['exceptions' => true, 'connection_timeout' => 10]
            );

            $result = $client->checkVat([
                'countryCode' => strtoupper($countryCode),
                'vatNumber' => preg_replace('/[^0-9A-Za-z]/', '', $vatNumber),
            ]);

            return [
                'available' => true,
                'valid' => (bool) $result->valid,
                'name' => $result->name !== '---' ? $result->name : null,
                'address' => $result->address !== '---' ? $result->address : null,
                'country_code' => $countryCode,
                'vat_number' => $vatNumber,
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
            ];
        }
    }
}
