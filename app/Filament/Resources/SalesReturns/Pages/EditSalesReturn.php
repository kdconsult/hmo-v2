<?php

namespace App\Filament\Resources\SalesReturns\Pages;

use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\SalesReturn;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesReturn extends EditRecord
{
    protected static string $resource = SalesReturnResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SalesReturn $sr */
        $sr = $this->getRecord();

        if (! $sr->isEditable()) {
            $this->redirect(SalesReturnResource::getUrl('view', ['record' => $sr]));
        }
    }

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
