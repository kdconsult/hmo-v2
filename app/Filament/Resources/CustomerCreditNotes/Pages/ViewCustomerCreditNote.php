<?php

namespace App\Filament\Resources\CustomerCreditNotes\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\CustomerCreditNote;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomerCreditNote extends ViewRecord
{
    protected static string $resource = CustomerCreditNoteResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        /** @var CustomerCreditNote $record */
        $record = $this->getRecord();
        $record->loadMissing('customerInvoice');
        $invoice = $record->customerInvoice;

        return [
            [
                'label' => 'Customer Invoice',
                'items' => [[
                    'number' => $invoice->invoice_number,
                    'status' => $invoice->status->getLabel(),
                    'color' => match ($invoice->status) {
                        DocumentStatus::Confirmed, DocumentStatus::Paid => 'success',
                        DocumentStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => CustomerInvoiceResource::getUrl('view', ['record' => $invoice]),
                ]],
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (CustomerCreditNote $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Credit Note')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (CustomerCreditNote $record): bool => $record->status === DocumentStatus::Draft)
                ->action(function (CustomerCreditNote $record): void {
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
                ->visible(fn (CustomerCreditNote $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (CustomerCreditNote $record): void {
                    $record->status = DocumentStatus::Cancelled;
                    $record->save();
                    Notification::make()->title('Credit note cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
