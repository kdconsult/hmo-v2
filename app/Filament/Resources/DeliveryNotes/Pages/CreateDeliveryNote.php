<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\NumberSeries;
use App\Models\SalesOrder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDeliveryNote extends CreateRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($soId = request()->query('sales_order_id')) {
            $so = SalesOrder::find($soId);
            if ($so) {
                $this->form->fill([
                    'sales_order_id' => $so->id,
                    'partner_id' => $so->partner_id,
                    'warehouse_id' => $so->warehouse_id,
                    'delivered_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['dn_number'])) {
            $series = NumberSeries::getDefault(SeriesType::DeliveryNote);

            if (! $series) {
                Notification::make()
                    ->title('No number series configured')
                    ->body('Go to Settings → Number Series and create one for Delivery Notes.')
                    ->danger()
                    ->persistent()
                    ->send();

                $this->halt();
            }

            $data['document_series_id'] = $series->id;
            $data['dn_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
