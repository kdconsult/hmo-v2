<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\NumberSeries;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

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
