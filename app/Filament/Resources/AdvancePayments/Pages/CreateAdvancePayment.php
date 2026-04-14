<?php

namespace App\Filament\Resources\AdvancePayments\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\AdvancePayments\AdvancePaymentResource;
use App\Models\NumberSeries;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAdvancePayment extends CreateRecord
{
    protected static string $resource = AdvancePaymentResource::class;

    protected function beforeCreate(): void
    {
        if (! NumberSeries::getDefault(SeriesType::AdvancePayment)) {
            Notification::make()
                ->title('No number series configured')
                ->body('Go to Settings → Number Series and create one for Advance Payments.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['ap_number'])) {
            $series = NumberSeries::getDefault(SeriesType::AdvancePayment);
            $data['document_series_id'] = $series->id;
            $data['ap_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
