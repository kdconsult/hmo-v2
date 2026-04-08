<?php

namespace App\Console\Commands;

use App\Models\VatRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncEuVatRatesCommand extends Command
{
    protected $signature = 'hmo:sync-eu-vat-rates';

    protected $description = 'Sync EU VAT rates from ibericode/vat-rates JSON';

    private const RATES_URL = 'https://raw.githubusercontent.com/ibericode/vat-rates/master/vat-rates.json';

    public function handle(): int
    {
        $this->info('Fetching EU VAT rates...');

        try {
            $response = Http::timeout(30)->get(self::RATES_URL);

            if (! $response->successful()) {
                $this->error('Failed to fetch rates: HTTP '.$response->status());

                return self::FAILURE;
            }

            $data = $response->json();
        } catch (\Exception $e) {
            $this->error('Failed to fetch rates: '.$e->getMessage());

            return self::FAILURE;
        }

        $synced = 0;
        $skipped = 0;

        foreach ($data as $countryCode => $rateData) {
            if (! is_array($rateData)) {
                continue;
            }

            $standardRate = $rateData['standard_rate'] ?? null;
            $reducedRates = $rateData['reduced_rates'] ?? [];

            if ($standardRate !== null) {
                VatRate::updateOrCreate(
                    ['country_code' => strtoupper($countryCode), 'type' => 'standard'],
                    [
                        'name' => 'Standard Rate',
                        'rate' => (float) $standardRate,
                        'is_default' => false,
                        'is_active' => true,
                        'sort_order' => 1,
                    ]
                );
                $synced++;
            }

            foreach ((array) $reducedRates as $index => $reducedRate) {
                VatRate::updateOrCreate(
                    ['country_code' => strtoupper($countryCode), 'type' => 'reduced', 'sort_order' => $index + 10],
                    [
                        'name' => 'Reduced Rate',
                        'rate' => (float) $reducedRate,
                        'is_default' => false,
                        'is_active' => true,
                    ]
                );
                $synced++;
            }
        }

        $this->info("Synced {$synced} VAT rates ({$skipped} skipped).");

        return self::SUCCESS;
    }
}
