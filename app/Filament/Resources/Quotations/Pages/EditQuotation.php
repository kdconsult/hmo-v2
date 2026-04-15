<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Quotation $quotation */
        $quotation = $this->getRecord();

        if (! $quotation->isEditable()) {
            $this->redirect(QuotationResource::getUrl('view', ['record' => $quotation]));
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
