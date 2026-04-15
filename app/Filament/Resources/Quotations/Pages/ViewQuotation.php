<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Quotation;
use App\Models\Warehouse;
use App\Services\QuotationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        $record = $this->getRecord();
        $record->loadMissing(['salesOrders']);

        return [
            [
                'label' => 'Sales Orders',
                'items' => $record->salesOrders->map(fn ($so) => [
                    'number' => $so->so_number,
                    'status' => $so->status->getLabel(),
                    'color' => $so->status->getColor(),
                    'url' => SalesOrderResource::getUrl('view', ['record' => $so]),
                ])->all(),
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Quotation $record): bool => $record->isEditable()),

            Action::make('send')
                ->label('Mark as Sent')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Draft)
                ->requiresConfirmation()
                ->action(function (Quotation $record): void {
                    try {
                        app(QuotationService::class)->transitionStatus($record, QuotationStatus::Sent);
                        Notification::make()->title('Quotation marked as sent')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Cannot send quotation')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('accept')
                ->label('Accept')
                ->icon(Heroicon::OutlinedHandThumbUp)
                ->color('success')
                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Sent)
                ->requiresConfirmation()
                ->action(function (Quotation $record): void {
                    try {
                        app(QuotationService::class)->transitionStatus($record, QuotationStatus::Accepted);
                        Notification::make()->title('Quotation accepted')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Cannot accept quotation')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon(Heroicon::OutlinedHandThumbDown)
                ->color('danger')
                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Sent)
                ->requiresConfirmation()
                ->action(function (Quotation $record): void {
                    try {
                        app(QuotationService::class)->transitionStatus($record, QuotationStatus::Rejected);
                        Notification::make()->title('Quotation rejected')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Cannot reject quotation')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('convert_to_so')
                ->label('Convert to Sales Order')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('info')
                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Accepted && ! $record->salesOrders()->exists())
                ->schema([
                    Select::make('warehouse_id')
                        ->label('Destination Warehouse')
                        ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (Quotation $record, array $data): void {
                    $warehouse = Warehouse::findOrFail($data['warehouse_id']);
                    $salesOrder = app(QuotationService::class)->convertToSalesOrder($record, $warehouse);
                    Notification::make()
                        ->title('Sales Order created')
                        ->body("Sales Order {$salesOrder->so_number} created successfully.")
                        ->success()
                        ->send();
                    $this->redirect(SalesOrderResource::getUrl('view', ['record' => $salesOrder]));
                }),

            Action::make('print_offer')
                ->label('Print as Offer')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Sent)
                ->action(function (Quotation $record): mixed {
                    $record->loadMissing(['partner', 'items.vatRate', 'items.productVariant']);
                    $pdf = Pdf::loadView('pdf.quotation-offer', ['quotation' => $record]);

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        "offer-{$record->quotation_number}.pdf",
                    );
                }),

            Action::make('print_proforma')
                ->label('Print as Proforma Invoice')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->visible(fn (Quotation $record): bool => in_array($record->status, [
                    QuotationStatus::Sent,
                    QuotationStatus::Accepted,
                ]))
                ->action(function (Quotation $record): mixed {
                    $record->loadMissing(['partner', 'items.vatRate', 'items.productVariant']);
                    $pdf = Pdf::loadView('pdf.quotation-proforma', ['quotation' => $record]);

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        "proforma-{$record->quotation_number}.pdf",
                    );
                }),

            Action::make('cancel')
                ->label('Cancel Quotation')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Quotation $record): bool => in_array($record->status, [
                    QuotationStatus::Draft,
                    QuotationStatus::Sent,
                    QuotationStatus::Accepted,
                ]))
                ->action(function (Quotation $record): void {
                    try {
                        app(QuotationService::class)->transitionStatus($record, QuotationStatus::Cancelled);
                        Notification::make()->title('Quotation cancelled')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Cannot cancel quotation')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
