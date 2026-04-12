<?php

namespace App\Filament\Resources\GoodsReceivedNotes\Pages;

use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Models\GoodsReceivedNote;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditGoodsReceivedNote extends EditRecord
{
    protected static string $resource = GoodsReceivedNoteResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var GoodsReceivedNote $grn */
        $grn = $this->getRecord();

        if (! $grn->isEditable()) {
            $this->redirect(GoodsReceivedNoteResource::getUrl('view', ['record' => $grn]));
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
