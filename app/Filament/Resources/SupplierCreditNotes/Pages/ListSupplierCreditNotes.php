<?php

namespace App\Filament\Resources\SupplierCreditNotes\Pages;

use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierCreditNotes extends ListRecords
{
    protected static string $resource = SupplierCreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
