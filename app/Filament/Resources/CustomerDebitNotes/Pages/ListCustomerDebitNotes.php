<?php

namespace App\Filament\Resources\CustomerDebitNotes\Pages;

use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerDebitNotes extends ListRecords
{
    protected static string $resource = CustomerDebitNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
