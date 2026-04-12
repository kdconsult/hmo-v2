<?php

namespace App\Filament\Resources\GoodsReceivedNotes\Pages;

use App\Enums\GoodsReceivedNoteStatus;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Models\GoodsReceivedNote;
use App\Services\GoodsReceiptService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewGoodsReceivedNote extends ViewRecord
{
    protected static string $resource = GoodsReceivedNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (GoodsReceivedNote $record): bool => $record->isEditable()),

            Action::make('confirm_receipt')
                ->label('Confirm Receipt')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Goods Receipt')
                ->modalDescription('This will receive all items into stock. This action cannot be undone.')
                ->visible(fn (GoodsReceivedNote $record): bool => $record->status === GoodsReceivedNoteStatus::Draft)
                ->action(function (GoodsReceivedNote $record): void {
                    try {
                        app(GoodsReceiptService::class)->confirm($record);
                        Notification::make()
                            ->title('Goods received successfully')
                            ->success()
                            ->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot confirm receipt')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (GoodsReceivedNote $record): bool => $record->status === GoodsReceivedNoteStatus::Draft)
                ->action(function (GoodsReceivedNote $record): void {
                    app(GoodsReceiptService::class)->cancel($record);
                }),
        ];
    }
}
