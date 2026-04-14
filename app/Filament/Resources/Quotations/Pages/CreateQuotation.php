<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\NumberSeries;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    protected function beforeCreate(): void
    {
        if (! NumberSeries::getDefault(SeriesType::Quote)) {
            Notification::make()
                ->title('No number series configured')
                ->body('Go to Settings → Number Series and create one for Quotes.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['quotation_number'])) {
            $series = NumberSeries::getDefault(SeriesType::Quote);
            $data['document_series_id'] = $series->id;
            $data['quotation_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
