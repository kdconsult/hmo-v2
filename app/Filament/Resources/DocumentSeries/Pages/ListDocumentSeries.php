<?php

namespace App\Filament\Resources\DocumentSeries\Pages;

use App\Filament\Resources\DocumentSeries\DocumentSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentSeries extends ListRecords
{
    protected static string $resource = DocumentSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
