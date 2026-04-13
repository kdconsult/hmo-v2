<?php

namespace App\Filament\Resources\GoodsReceivedNotes\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Models\NumberSeries;
use App\Models\PurchaseOrder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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
        if (empty($data['grn_number'])) {
            $series = NumberSeries::getDefault(SeriesType::GoodsReceivedNote);
            if (! $series) {
                throw ValidationException::withMessages([
                    'grn_number' => 'No active number series configured for Goods Receipts. Go to Settings → Number Series.',
                ]);
            }
            $data['document_series_id'] = $series->id;
            $data['grn_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
