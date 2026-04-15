<?php

namespace App\Filament\Resources\AdvancePayments\Pages;

use App\Enums\AdvancePaymentStatus;
use App\Enums\DocumentStatus;
use App\Filament\Resources\AdvancePayments\AdvancePaymentResource;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\AdvancePayment;
use App\Models\CustomerInvoice;
use App\Services\AdvancePaymentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAdvancePayment extends ViewRecord
{
    protected static string $resource = AdvancePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (AdvancePayment $record): bool => $record->isEditable()),

            Action::make('issue_advance_invoice')
                ->label('Issue Advance Invoice')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Issue Advance Invoice')
                ->modalDescription('This will create and confirm a Customer Invoice (type: Advance) linked to this payment.')
                ->visible(fn (AdvancePayment $record): bool => ! $record->customer_invoice_id &&
                    in_array($record->status, [AdvancePaymentStatus::Open, AdvancePaymentStatus::PartiallyApplied])
                )
                ->action(function (AdvancePayment $record): void {
                    try {
                        $invoice = app(AdvancePaymentService::class)->createAdvanceInvoice($record);
                        Notification::make()
                            ->title('Advance invoice created')
                            ->success()
                            ->send();
                        $this->redirect(CustomerInvoiceResource::getUrl('view', ['record' => $invoice]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot issue advance invoice')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('apply_to_invoice')
                ->label('Apply to Invoice')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->visible(fn (AdvancePayment $record): bool => $record->customer_invoice_id !== null &&
                    in_array($record->status, [AdvancePaymentStatus::Open, AdvancePaymentStatus::PartiallyApplied])
                )
                ->schema(fn (AdvancePayment $record): array => [
                    Select::make('customer_invoice_id')
                        ->label('Customer Invoice')
                        ->options(
                            CustomerInvoice::where('partner_id', $record->partner_id)
                                ->where('status', DocumentStatus::Draft->value)
                                ->where('id', '!=', $record->customer_invoice_id)
                                ->get()
                                ->mapWithKeys(fn (CustomerInvoice $inv) => [$inv->id => $inv->invoice_number])
                        )
                        ->required(),
                    TextInput::make('amount')
                        ->label('Amount to Apply')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue((float) $record->remainingAmount())
                        ->default($record->remainingAmount())
                        ->step('0.01'),
                ])
                ->action(function (AdvancePayment $record, array $data): void {
                    try {
                        $invoice = CustomerInvoice::findOrFail($data['customer_invoice_id']);
                        app(AdvancePaymentService::class)->applyToFinalInvoice($record, $invoice, $data['amount']);
                        Notification::make()
                            ->title('Advance payment applied')
                            ->success()
                            ->send();
                        $this->redirect(AdvancePaymentResource::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot apply advance payment')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('refund')
                ->label('Refund')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Refund Advance Payment')
                ->modalDescription('Mark this advance payment as refunded to the customer. This action cannot be undone.')
                ->visible(fn (AdvancePayment $record): bool => in_array($record->status, [AdvancePaymentStatus::Open, AdvancePaymentStatus::PartiallyApplied])
                )
                ->action(function (AdvancePayment $record): void {
                    app(AdvancePaymentService::class)->refund($record);
                    Notification::make()->title('Advance payment refunded')->success()->send();
                    $this->redirect(AdvancePaymentResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
