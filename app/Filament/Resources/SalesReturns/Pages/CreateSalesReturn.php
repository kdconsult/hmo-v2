<?php

namespace App\Filament\Resources\SalesReturns\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\DeliveryNote;
use App\Models\NumberSeries;
use App\Services\SalesReturnService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSalesReturn extends CreateRecord
{
    protected static string $resource = SalesReturnResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($dnId = request()->query('delivery_note_id')) {
            $dn = DeliveryNote::find($dnId);
            if ($dn) {
                $this->form->fill([
                    'delivery_note_id' => $dn->id,
                    'partner_id' => $dn->partner_id,
                    'warehouse_id' => $dn->warehouse_id,
                    'returned_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['sr_number'])) {
            $series = NumberSeries::getDefault(SeriesType::SalesReturn);

            if (! $series) {
                Notification::make()
                    ->title('No number series configured')
                    ->body('Go to Settings → Number Series and create one for Sales Returns.')
                    ->danger()
                    ->persistent()
                    ->send();

                $this->halt();
            }

            $data['document_series_id'] = $series->id;
            $data['sr_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        if (empty($data['partner_id']) && ! empty($data['delivery_note_id'])) {
            $dn = DeliveryNote::find($data['delivery_note_id']);
            if ($dn) {
                $data['partner_id'] = $dn->partner_id;
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->record->delivery_note_id) {
            return;
        }

        app(SalesReturnService::class)->autoFillItemsFromDeliveryNote($this->record);
    }
}
