<?php

namespace App\Filament\Resources\CustomerInvoices\Pages;

use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\CustomerInvoice;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomerInvoice extends EditRecord
{
    protected static string $resource = CustomerInvoiceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var CustomerInvoice $invoice */
        $invoice = $this->getRecord();

        if (! $invoice->isEditable()) {
            $this->redirect(CustomerInvoiceResource::getUrl('view', ['record' => $invoice]));
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
