<?php

namespace App\Filament\Resources\SupplierInvoices\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\SupplierInvoice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierInvoice extends ViewRecord
{
    protected static string $resource = SupplierInvoiceResource::class;

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
                }),
        ];
    }
}
