<?php

namespace App\Filament\Resources\DocumentSeries\Pages;

use App\Filament\Resources\DocumentSeries\DocumentSeriesResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDocumentSeries extends ViewRecord
{
    protected static string $resource = DocumentSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
