<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\NumberSeries;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['po_number'])) {
            $series = NumberSeries::getDefault(SeriesType::PurchaseOrder);
            if (! $series) {
                throw ValidationException::withMessages([
                    'po_number' => 'No active number series configured for Purchase Orders. Go to Settings → Number Series.',
                ]);
            }
            $data['document_series_id'] = $series->id;
            $data['po_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
