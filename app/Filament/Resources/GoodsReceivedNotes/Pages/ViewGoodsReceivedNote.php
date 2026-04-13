<?php

namespace App\Filament\Resources\GoodsReceivedNotes\Pages;

use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseReturnStatus;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
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

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        /** @var GoodsReceivedNote $record */
        $record = $this->getRecord();
        $groups = [];

        if ($record->purchase_order_id) {
            $record->loadMissing('purchaseOrder');
            $po = $record->purchaseOrder;

            $groups[] = [
                'label' => 'Purchase Order',
                'items' => [[
                    'number' => $po->po_number,
                    'status' => $po->status->value,
                    'color' => match ($po->status) {
                        PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Received => 'success',
                        PurchaseOrderStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => PurchaseOrderResource::getUrl('view', ['record' => $po]),
                ]],
            ];
        }

        $record->loadMissing('purchaseReturns');

        if ($record->purchaseReturns->isNotEmpty()) {
            $groups[] = [
                'label' => 'Purchase Returns',
                'items' => $record->purchaseReturns->map(fn ($pr) => [
                    'number' => $pr->pr_number,
                    'status' => $pr->status->value,
                    'color' => match ($pr->status) {
                        PurchaseReturnStatus::Confirmed => 'success',
                        PurchaseReturnStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => PurchaseReturnResource::getUrl('view', ['record' => $pr]),
                ])->toArray(),
            ];
        }

        return $groups;
    }

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
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot confirm receipt')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('create_return')
                ->label('Create Return')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->visible(fn (GoodsReceivedNote $record): bool => $record->status === GoodsReceivedNoteStatus::Confirmed)
                ->url(fn (GoodsReceivedNote $record): string => PurchaseReturnResource::getUrl('create').'?goods_received_note_id='.$record->id),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (GoodsReceivedNote $record): bool => $record->status === GoodsReceivedNoteStatus::Draft)
                ->action(function (GoodsReceivedNote $record): void {
                    app(GoodsReceiptService::class)->cancel($record);
                    Notification::make()->title('Receipt cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
