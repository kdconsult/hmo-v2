<?php

namespace App\Filament\Resources\NumberSeries\Pages;

use App\Filament\Resources\NumberSeries\NumberSeriesResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNumberSeries extends ViewRecord
{
    protected static string $resource = NumberSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
