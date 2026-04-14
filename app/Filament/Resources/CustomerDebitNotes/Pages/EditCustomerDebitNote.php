<?php

namespace App\Filament\Resources\CustomerDebitNotes\Pages;

use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomerDebitNote extends EditRecord
{
    protected static string $resource = CustomerDebitNoteResource::class;

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
