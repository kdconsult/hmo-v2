<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\NumberSeries;
use App\Models\Quotation;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($quotationId = request()->query('quotation_id')) {
            $quotation = Quotation::find($quotationId);
            if ($quotation) {
                $this->form->fill([
                    'quotation_id' => $quotation->id,
                    'partner_id' => $quotation->partner_id,
                    'currency_code' => $quotation->currency_code,
                    'exchange_rate' => $quotation->exchange_rate,
                    'pricing_mode' => $quotation->pricing_mode->value,
                    'issued_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['so_number'])) {
            $series = NumberSeries::getDefault(SeriesType::SalesOrder);

            if (! $series) {
                Notification::make()
                    ->title('No number series configured')
                    ->body('Go to Settings → Number Series and create one for Sales Orders.')
                    ->danger()
                    ->persistent()
                    ->send();

                $this->halt();
            }

            $data['document_series_id'] = $series->id;
            $data['so_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
