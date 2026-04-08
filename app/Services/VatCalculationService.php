<?php

namespace App\Services;

use App\Enums\PricingMode;

class VatCalculationService
{
    /**
     * Calculate VAT from an exclusive (net) amount.
     *
     * @return array{net: float, vat: float, gross: float, rate: float}
     */
    public function fromNet(float $net, float $rate): array
    {
        $vat = round($net * ($rate / 100), 2);

        return [
            'net' => round($net, 2),
            'vat' => $vat,
            'gross' => round($net + $vat, 2),
            'rate' => $rate,
        ];
    }

    /**
     * Calculate VAT from an inclusive (gross) amount.
     *
     * @return array{net: float, vat: float, gross: float, rate: float}
     */
    public function fromGross(float $gross, float $rate): array
    {
        $net = round($gross / (1 + $rate / 100), 2);
        $vat = round($gross - $net, 2);

        return [
            'net' => $net,
            'vat' => $vat,
            'gross' => round($gross, 2),
            'rate' => $rate,
        ];
    }

    /**
     * Calculate VAT based on pricing mode.
     *
     * @return array{net: float, vat: float, gross: float, rate: float}
     */
    public function calculate(float $amount, float $rate, PricingMode $mode): array
    {
        return match ($mode) {
            PricingMode::VatExclusive => $this->fromNet($amount, $rate),
            PricingMode::VatInclusive => $this->fromGross($amount, $rate),
        };
    }

    /**
     * Calculate totals for a multi-line document.
     *
     * Each line: ['amount' => float, 'rate' => float, 'mode' => PricingMode]
     *
     * @param  array<int, array{amount: float, rate: float, mode: PricingMode}>  $lines
     * @return array{net: float, vat: float, gross: float, vat_breakdown: array<string, float>}
     */
    public function calculateDocument(array $lines): array
    {
        $totalNet = 0.0;
        $totalVat = 0.0;
        $vatBreakdown = [];

        foreach ($lines as $line) {
            $result = $this->calculate($line['amount'], $line['rate'], $line['mode']);
            $totalNet += $result['net'];
            $totalVat += $result['vat'];

            $rateKey = number_format($line['rate'], 2).'%';
            $vatBreakdown[$rateKey] = ($vatBreakdown[$rateKey] ?? 0.0) + $result['vat'];
        }

        return [
            'net' => round($totalNet, 2),
            'vat' => round($totalVat, 2),
            'gross' => round($totalNet + $totalVat, 2),
            'vat_breakdown' => $vatBreakdown,
        ];
    }
}
