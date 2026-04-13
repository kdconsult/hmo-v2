<?php

namespace App\Filament\Resources\GoodsReceivedNotes\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Models\NumberSeries;
use App\Models\PurchaseOrder;
use Filament\Notifications\Notification;
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

    protected function beforeCreate(): void
    {
        if (! NumberSeries::getDefault(SeriesType::GoodsReceivedNote)) {
            Notification::make()
                ->title('No number series configured')
                ->body('Go to Settings → Number Series and create one for Goods Receipts.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['grn_number'])) {
            $series = NumberSeries::getDefault(SeriesType::GoodsReceivedNote);
            $data['document_series_id'] = $series->id;
            $data['grn_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
