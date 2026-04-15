<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\NumberSeries;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['po_number'])) {
            $series = NumberSeries::getDefault(SeriesType::PurchaseOrder);

            if (! $series) {
                Notification::make()
                    ->title('No number series configured')
                    ->body('Go to Settings → Number Series and create one for Purchase Orders.')
                    ->danger()
                    ->persistent()
                    ->send();

                $this->halt();
            }

            $data['document_series_id'] = $series->id;
            $data['po_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
