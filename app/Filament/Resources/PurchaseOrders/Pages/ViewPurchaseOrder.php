<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\DocumentStatus;
use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        $record = $this->getRecord();
        $record->loadMissing(['goodsReceivedNotes', 'supplierInvoices']);

        return [
            [
                'label' => 'Goods Receipts',
                'items' => $record->goodsReceivedNotes->map(fn ($grn) => [
                    'number' => $grn->grn_number,
                    'status' => $grn->status->value,
                    'color' => match ($grn->status) {
                        GoodsReceivedNoteStatus::Confirmed => 'success',
                        GoodsReceivedNoteStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => GoodsReceivedNoteResource::getUrl('view', ['record' => $grn]),
                ])->all(),
            ],
            [
                'label' => 'Supplier Invoices',
                'items' => $record->supplierInvoices->map(fn ($si) => [
                    'number' => $si->internal_number,
                    'status' => $si->status->value,
                    'color' => match ($si->status) {
                        DocumentStatus::Confirmed, DocumentStatus::Paid => 'success',
                        DocumentStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => SupplierInvoiceResource::getUrl('view', ['record' => $si]),
                ])->all(),
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (PurchaseOrder $record): bool => $record->isEditable()),

            Action::make('send')
                ->label('Mark as Sent')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft)
                ->requiresConfirmation()
                ->action(function (PurchaseOrder $record): void {
                    app(PurchaseOrderService::class)->transitionStatus($record, PurchaseOrderStatus::Sent);
                    Notification::make()->title('Order marked as sent')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('confirm')
                ->label('Confirm')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Sent)
                ->requiresConfirmation()
                ->action(function (PurchaseOrder $record): void {
                    app(PurchaseOrderService::class)->transitionStatus($record, PurchaseOrderStatus::Confirmed);
                    Notification::make()->title('Order confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('create_grn')
                ->label('Create Goods Receipt')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('info')
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [
                    PurchaseOrderStatus::Confirmed,
                    PurchaseOrderStatus::PartiallyReceived,
                ]))
                ->url(fn (PurchaseOrder $record): string => GoodsReceivedNoteResource::getUrl('create').'?purchase_order_id='.$record->id
                ),

            Action::make('create_supplier_invoice')
                ->label('Create Supplier Invoice')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('warning')
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [
                    PurchaseOrderStatus::Confirmed,
                    PurchaseOrderStatus::PartiallyReceived,
                    PurchaseOrderStatus::Received,
                ]))
                ->url(fn (PurchaseOrder $record): string => SupplierInvoiceResource::getUrl('create').'?purchase_order_id='.$record->id
                ),

            Action::make('cancel')
                ->label('Cancel Order')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(function (PurchaseOrder $record): ?string {
                    $draftGrns = $record->goodsReceivedNotes()
                        ->where('status', GoodsReceivedNoteStatus::Draft->value)
                        ->count();
                    $draftSis = $record->supplierInvoices()
                        ->where('status', DocumentStatus::Draft->value)
                        ->count();

                    if ($draftGrns === 0 && $draftSis === 0) {
                        return null;
                    }

                    $parts = [];
                    if ($draftGrns > 0) {
                        $parts[] = "{$draftGrns} draft Goods Receipt".($draftGrns > 1 ? 's' : '');
                    }
                    if ($draftSis > 0) {
                        $parts[] = "{$draftSis} draft Supplier Invoice".($draftSis > 1 ? 's' : '');
                    }

                    return 'This will also cancel: '.implode(', ', $parts).'.';
                })
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [
                    PurchaseOrderStatus::Draft,
                    PurchaseOrderStatus::Sent,
                    PurchaseOrderStatus::Confirmed,
                ]))
                ->action(function (PurchaseOrder $record): void {
                    try {
                        app(PurchaseOrderService::class)->transitionStatus($record, PurchaseOrderStatus::Cancelled);
                        Notification::make()->title('Order cancelled')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Cannot cancel order')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
