<?php

namespace App\Filament\Resources\CustomerInvoices\Pages;

use App\Enums\DocumentStatus;
use App\Enums\VatScenario;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Filament\Resources\CustomerDebitNotes\CustomerDebitNoteResource;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\CompanySettings;
use App\Models\CustomerInvoice;
use App\Services\CustomerInvoiceService;
use App\Services\ViesValidationService;
use App\Support\EuCountries;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ViewCustomerInvoice extends ViewRecord
{
    protected static string $resource = CustomerInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (CustomerInvoice $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Invoice')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (CustomerInvoice $record): bool => $record->status === DocumentStatus::Draft)
                ->action(function (CustomerInvoice $record): void {
                    try {
                        $record->loadMissing('partner');
                        $tenantCountry = CompanySettings::get('company', 'country_code');

                        if ($tenantCountry && $record->partner) {
                            $scenario = VatScenario::determine($record->partner, $tenantCountry);

                            if ($scenario === VatScenario::EuB2bReverseCharge) {
                                $vatNumber = EuCountries::extractMainVatNumber(
                                    $record->partner->country_code,
                                    $record->partner->vat_number
                                );
                                $viesResult = app(ViesValidationService::class)->validate(
                                    $record->partner->country_code,
                                    $vatNumber
                                );

                                if (! $viesResult['valid']) {
                                    Notification::make()
                                        ->title('VIES validation warning')
                                        ->body("The partner's EU VAT number could not be confirmed via VIES. Reverse charge will still be applied based on the stored VAT number.")
                                        ->warning()
                                        ->send();
                                }
                            }
                        }

                        app(CustomerInvoiceService::class)->confirm($record);
                        Notification::make()->title('Invoice confirmed')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Cannot confirm invoice')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('print_invoice')
                ->label('Print Invoice')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (CustomerInvoice $record): bool => $record->status === DocumentStatus::Confirmed)
                ->action(function (CustomerInvoice $record) {
                    $record->loadMissing(['partner', 'items.productVariant', 'items.vatRate']);

                    return response()->streamDownload(
                        function () use ($record) {
                            $pdf = Pdf::loadView('pdf.customer-invoice', ['invoice' => $record]);
                            echo $pdf->output();
                        },
                        "invoice-{$record->invoice_number}.pdf"
                    );
                }),

            Action::make('create_credit_note')
                ->label('Create Credit Note')
                ->icon(Heroicon::OutlinedDocumentMinus)
                ->color('warning')
                ->visible(fn (CustomerInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Confirmed,
                    DocumentStatus::Paid,
                ]))
                ->url(fn (CustomerInvoice $record): string => CustomerCreditNoteResource::getUrl('create').'?customer_invoice_id='.$record->id),

            Action::make('create_debit_note')
                ->label('Create Debit Note')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->color('info')
                ->visible(fn (CustomerInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Confirmed,
                    DocumentStatus::Paid,
                ]))
                ->url(fn (CustomerInvoice $record): string => CustomerDebitNoteResource::getUrl('create').'?customer_invoice_id='.$record->id),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (CustomerInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (CustomerInvoice $record): void {
                    app(CustomerInvoiceService::class)->cancel($record);
                    Notification::make()->title('Invoice cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
