<?php

namespace App\Filament\Resources\SalesReturns\Pages;

use App\Enums\DocumentStatus;
use App\Enums\SalesReturnStatus;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\SalesReturn;
use App\Services\SalesReturnService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSalesReturn extends ViewRecord
{
    protected static string $resource = SalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (SalesReturn $record): bool => $record->isEditable()),

            Action::make('confirm_return')
                ->label('Confirm Return')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Sales Return')
                ->modalDescription('This will receive all returned items back into stock. This action cannot be undone.')
                ->visible(fn (SalesReturn $record): bool => $record->status === SalesReturnStatus::Draft)
                ->action(function (SalesReturn $record): void {
                    try {
                        app(SalesReturnService::class)->confirm($record);
                        Notification::make()
                            ->title('Sales return confirmed')
                            ->success()
                            ->send();
                        $this->redirect(SalesReturnResource::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot confirm return')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('create_credit_note')
                ->label('Create Credit Note')
                ->icon(Heroicon::OutlinedDocumentMinus)
                ->color('warning')
                ->visible(fn (SalesReturn $record): bool => $record->isConfirmed() && $this->getLinkedInvoiceOptions($record) !== [])
                ->schema(fn (SalesReturn $record): array => [
                    Select::make('customer_invoice_id')
                        ->label('Invoice')
                        ->options($this->getLinkedInvoiceOptions($record))
                        ->required(),
                ])
                ->action(fn (SalesReturn $record, array $data): mixed => $this->redirect(
                    CustomerCreditNoteResource::getUrl('create').'?customer_invoice_id='.$data['customer_invoice_id'].'&sales_return_id='.$record->id
                )),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (SalesReturn $record): bool => $record->status === SalesReturnStatus::Draft)
                ->action(function (SalesReturn $record): void {
                    app(SalesReturnService::class)->cancel($record);
                    Notification::make()->title('Sales return cancelled')->success()->send();
                    $this->redirect(SalesReturnResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private function getLinkedInvoiceOptions(SalesReturn $record): array
    {
        if (! $record->delivery_note_id) {
            return [];
        }

        $record->loadMissing('deliveryNote.salesOrder.customerInvoices');
        $so = $record->deliveryNote?->salesOrder;

        if (! $so) {
            return [];
        }

        return $so->customerInvoices
            ->where('status', DocumentStatus::Confirmed)
            ->mapWithKeys(fn ($invoice): array => [$invoice->id => $invoice->invoice_number])
            ->all();
    }
}
