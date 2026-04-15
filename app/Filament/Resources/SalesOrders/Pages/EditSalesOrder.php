<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var SalesOrder $so */
        $so = $this->getRecord();

        if (! $so->isEditable()) {
            $this->redirect(SalesOrderResource::getUrl('view', ['record' => $so]));
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
