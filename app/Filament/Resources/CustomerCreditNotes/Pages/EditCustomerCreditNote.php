<?php

namespace App\Filament\Resources\CustomerCreditNotes\Pages;

use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomerCreditNote extends EditRecord
{
    protected static string $resource = CustomerCreditNoteResource::class;

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
