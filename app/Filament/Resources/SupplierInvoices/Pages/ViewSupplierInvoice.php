<?php

namespace App\Filament\Resources\SupplierInvoices\Pages;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\SupplierInvoice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierInvoice extends ViewRecord
{
    protected static string $resource = SupplierInvoiceResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        $record = $this->getRecord();
        $record->loadMissing(['purchaseOrder', 'creditNotes']);

        $groups = [];

        if ($record->purchase_order_id) {
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

        if ($record->creditNotes->isNotEmpty()) {
            $groups[] = [
                'label' => 'Credit Notes',
                'items' => $record->creditNotes->map(fn ($cn) => [
                    'number' => $cn->credit_note_number,
                    'status' => $cn->status->value,
                    'color' => match ($cn->status) {
                        DocumentStatus::Confirmed, DocumentStatus::Paid => 'success',
                        DocumentStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => SupplierCreditNoteResource::getUrl('view', ['record' => $cn]),
                ])->all(),
            ];
        }

        return $groups;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (SupplierInvoice $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Invoice')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (SupplierInvoice $record): bool => $record->status === DocumentStatus::Draft)
                ->action(function (SupplierInvoice $record): void {
                    $record->status = DocumentStatus::Confirmed;
                    $record->save();
                    Notification::make()->title('Invoice confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('create_credit_note')
                ->label('Create Credit Note')
                ->icon(Heroicon::OutlinedDocumentMinus)
                ->color('warning')
                ->visible(fn (SupplierInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Confirmed,
                    DocumentStatus::Paid,
                ]))
                ->url(fn (SupplierInvoice $record): string => SupplierCreditNoteResource::getUrl('create').'?supplier_invoice_id='.$record->id
                ),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (SupplierInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (SupplierInvoice $record): void {
                    $record->status = DocumentStatus::Cancelled;
                    $record->save();
                    Notification::make()->title('Invoice cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
