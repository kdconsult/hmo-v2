<?php

namespace App\Filament\Resources\CustomerCreditNotes\Pages;

use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerCreditNotes extends ListRecords
{
    protected static string $resource = CustomerCreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
