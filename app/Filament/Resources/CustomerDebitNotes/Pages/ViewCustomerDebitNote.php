<?php

namespace App\Filament\Resources\CustomerDebitNotes\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\CustomerDebitNote;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomerDebitNote extends ViewRecord
{
    protected static string $resource = CustomerDebitNoteResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        /** @var CustomerDebitNote $record */
        $record = $this->getRecord();
        $record->loadMissing('customerInvoice');

        if (! $record->customerInvoice) {
            return [];
        }

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
                ->visible(fn (CustomerDebitNote $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Debit Note')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (CustomerDebitNote $record): bool => $record->status === DocumentStatus::Draft)
                ->action(function (CustomerDebitNote $record): void {
                    $record->status = DocumentStatus::Confirmed;
                    $record->save();
                    Notification::make()->title('Debit note confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (CustomerDebitNote $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (CustomerDebitNote $record): void {
                    $record->status = DocumentStatus::Cancelled;
                    $record->save();
                    Notification::make()->title('Debit note cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
