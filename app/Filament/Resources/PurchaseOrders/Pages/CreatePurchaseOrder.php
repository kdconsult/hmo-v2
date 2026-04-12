<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\NumberSeries;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['document_series_id']) && empty($data['po_number'])) {
            $series = NumberSeries::find($data['document_series_id']);
            if ($series) {
                $data['po_number'] = $series->generateNumber();
            }
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
