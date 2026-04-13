<?php

namespace App\Filament\Resources\PurchaseReturns\Pages;

use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\PurchaseReturnStatus;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchaseReturn extends ViewRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        /** @var PurchaseReturn $record */
        $record = $this->getRecord();

        if (! $record->goods_received_note_id) {
            return [];
        }

        $record->loadMissing('goodsReceivedNote');
        $grn = $record->goodsReceivedNote;

        return [
            [
                'label' => 'Goods Receipt',
                'items' => [[
                    'number' => $grn->grn_number,
                    'status' => $grn->status->value,
                    'color' => match ($grn->status) {
                        GoodsReceivedNoteStatus::Confirmed => 'success',
                        GoodsReceivedNoteStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => GoodsReceivedNoteResource::getUrl('view', ['record' => $grn]),
                ]],
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (PurchaseReturn $record): bool => $record->isEditable()),

            Action::make('confirm_return')
                ->label('Confirm Return')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Purchase Return')
                ->modalDescription('This will issue all items out of stock back to the supplier. This action cannot be undone.')
                ->visible(fn (PurchaseReturn $record): bool => $record->status === PurchaseReturnStatus::Draft)
                ->action(function (PurchaseReturn $record): void {
                    try {
                        app(PurchaseReturnService::class)->confirm($record);
                        Notification::make()
                            ->title('Purchase return confirmed')
                            ->success()
                            ->send();
                        $this->redirect(PurchaseReturnResource::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot confirm return')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } catch (InsufficientStockException $e) {
                        Notification::make()
                            ->title('Insufficient stock')
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
                ->visible(fn (PurchaseReturn $record): bool => $record->status === PurchaseReturnStatus::Draft)
                ->action(function (PurchaseReturn $record): void {
                    app(PurchaseReturnService::class)->cancel($record);
                    Notification::make()->title('Purchase return cancelled')->success()->send();
                    $this->redirect(PurchaseReturnResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
