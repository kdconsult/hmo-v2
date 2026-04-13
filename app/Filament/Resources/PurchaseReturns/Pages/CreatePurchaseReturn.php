<?php

namespace App\Filament\Resources\PurchaseReturns\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Models\GoodsReceivedNote;
use App\Models\NumberSeries;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseReturn extends CreateRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($grnId = request()->query('goods_received_note_id')) {
            $grn = GoodsReceivedNote::find($grnId);
            if ($grn) {
                $this->form->fill([
                    'goods_received_note_id' => $grn->id,
                    'partner_id' => $grn->partner_id,
                    'warehouse_id' => $grn->warehouse_id,
                    'returned_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function beforeCreate(): void
    {
        if (! NumberSeries::getDefault(SeriesType::PurchaseReturn)) {
            Notification::make()
                ->title('No number series configured')
                ->body('Go to Settings → Number Series and create one for Purchase Returns.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['pr_number'])) {
            $series = NumberSeries::getDefault(SeriesType::PurchaseReturn);
            $data['document_series_id'] = $series->id;
            $data['pr_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        if (empty($data['partner_id']) && ! empty($data['goods_received_note_id'])) {
            $grn = GoodsReceivedNote::find($data['goods_received_note_id']);
            if ($grn) {
                $data['partner_id'] = $grn->partner_id;
            }
        }

        return $data;
    }
}
