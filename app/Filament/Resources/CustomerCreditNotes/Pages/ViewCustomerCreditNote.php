<?php

namespace App\Filament\Resources\CustomerCreditNotes\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Models\CustomerCreditNote;
use App\Services\CustomerCreditNoteService;
use App\Services\PdfTemplateResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomerCreditNote extends ViewRecord
{
    protected static string $resource = CustomerCreditNoteResource::class;

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
                    app(CustomerCreditNoteService::class)->confirm($record);
                    Notification::make()->title('Credit note confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('print_credit_note')
                ->label('Print Credit Note')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (CustomerCreditNote $record): bool => $record->status === DocumentStatus::Confirmed)
                ->action(function (CustomerCreditNote $record) {
                    $record->loadMissing(['partner.addresses', 'items.productVariant', 'items.vatRate', 'customerInvoice']);

                    $resolver = app(PdfTemplateResolver::class);
                    $view = $resolver->resolve('customer-credit-note');
                    $locale = $resolver->localeFor('customer-credit-note');

                    $previous = app()->getLocale();
                    app()->setLocale($locale);

                    try {
                        return response()->streamDownload(
                            function () use ($view, $record) {
                                $pdf = Pdf::loadView($view, ['note' => $record]);
                                echo $pdf->output();
                            },
                            "credit-note-{$record->credit_note_number}.pdf"
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
                ->visible(fn (CustomerCreditNote $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (CustomerCreditNote $record): void {
                    app(CustomerCreditNoteService::class)->cancel($record);
                    Notification::make()->title('Credit note cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
