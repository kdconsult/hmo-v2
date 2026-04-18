<?php

namespace App\Filament\Resources\CustomerDebitNotes\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use App\Models\CustomerDebitNote;
use App\Services\CustomerDebitNoteService;
use App\Services\PdfTemplateResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomerDebitNote extends ViewRecord
{
    protected static string $resource = CustomerDebitNoteResource::class;

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
                    app(CustomerDebitNoteService::class)->confirm($record);
                    Notification::make()->title('Debit note confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('print_debit_note')
                ->label('Print Debit Note')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (CustomerDebitNote $record): bool => $record->status === DocumentStatus::Confirmed)
                ->action(function (CustomerDebitNote $record) {
                    $record->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate', 'customerInvoice']);

                    $resolver = app(PdfTemplateResolver::class);
                    $view = $resolver->resolve('customer-debit-note');
                    $locale = $resolver->localeFor('customer-debit-note');

                    $previous = app()->getLocale();
                    app()->setLocale($locale);

                    try {
                        return response()->streamDownload(
                            function () use ($view, $record) {
                                $pdf = Pdf::loadView($view, ['note' => $record]);
                                echo $pdf->output();
                            },
                            "debit-note-{$record->debit_note_number}.pdf"
                        );
                    } finally {
                        app()->setLocale($previous);
                    }
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
                    app(CustomerDebitNoteService::class)->cancel($record);
                    Notification::make()->title('Debit note cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
