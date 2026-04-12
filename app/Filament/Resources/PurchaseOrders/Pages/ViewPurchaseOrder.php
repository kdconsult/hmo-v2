<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (PurchaseOrder $record): bool => $record->isEditable()),

            Action::make('send')
                ->label('Send to Supplier')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft)
                ->requiresConfirmation()
                ->action(fn (PurchaseOrder $record) => app(PurchaseOrderService::class)->transitionStatus($record, PurchaseOrderStatus::Sent)),

            Action::make('confirm')
                ->label('Confirm')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Sent)
                ->requiresConfirmation()
                ->action(fn (PurchaseOrder $record) => app(PurchaseOrderService::class)->transitionStatus($record, PurchaseOrderStatus::Confirmed)),

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
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [
                    PurchaseOrderStatus::Draft,
                    PurchaseOrderStatus::Sent,
                    PurchaseOrderStatus::Confirmed,
                ]))
                ->action(fn (PurchaseOrder $record) => app(PurchaseOrderService::class)->transitionStatus($record, PurchaseOrderStatus::Cancelled)),
        ];
    }
}
