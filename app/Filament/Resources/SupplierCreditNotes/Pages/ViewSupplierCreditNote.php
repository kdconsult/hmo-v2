<?php

namespace App\Filament\Resources\SupplierCreditNotes\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\SupplierCreditNote;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSupplierCreditNote extends ViewRecord
{
    protected static string $resource = SupplierCreditNoteResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        $record = $this->getRecord();
        $record->loadMissing('supplierInvoice');
        $si = $record->supplierInvoice;

        return [
            [
                'label' => 'Supplier Invoice',
                'items' => [[
                    'number' => $si->internal_number,
                    'status' => $si->status->value,
                    'color' => match ($si->status) {
                        DocumentStatus::Confirmed, DocumentStatus::Paid => 'success',
                        DocumentStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => SupplierInvoiceResource::getUrl('view', ['record' => $si]),
                ]],
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (SupplierCreditNote $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Credit Note')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (SupplierCreditNote $record): bool => $record->status === DocumentStatus::Draft)
                ->action(function (SupplierCreditNote $record): void {
                    $record->status = DocumentStatus::Confirmed;
                    $record->save();
                    Notification::make()->title('Credit note confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (SupplierCreditNote $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (SupplierCreditNote $record): void {
                    $record->status = DocumentStatus::Cancelled;
                    $record->save();
                    Notification::make()->title('Credit note cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
