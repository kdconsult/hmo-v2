<?php

namespace App\Filament\Resources\GoodsReceivedNotes\Pages;

use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Models\NumberSeries;
use App\Models\PurchaseOrder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateGoodsReceivedNote extends CreateRecord
{
    protected static string $resource = GoodsReceivedNoteResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($poId = request()->query('purchase_order_id')) {
            $po = PurchaseOrder::find($poId);
            if ($po) {
                $this->form->fill([
                    'purchase_order_id' => $po->id,
                    'partner_id' => $po->partner_id,
                    'warehouse_id' => $po->warehouse_id,
                    'received_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['document_series_id']) && empty($data['grn_number'])) {
            $series = NumberSeries::find($data['document_series_id']);
            if ($series) {
                $data['grn_number'] = $series->generateNumber();
            }
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
