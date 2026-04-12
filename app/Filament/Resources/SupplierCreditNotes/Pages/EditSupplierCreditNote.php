<?php

namespace App\Filament\Resources\SupplierCreditNotes\Pages;

use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSupplierCreditNote extends EditRecord
{
    protected static string $resource = SupplierCreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
