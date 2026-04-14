<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Enums\DeliveryNoteStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SalesReturnStatus;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\DeliveryNote;
use App\Services\DeliveryNoteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        /** @var DeliveryNote $record */
        $record = $this->getRecord();
        $groups = [];

        if ($record->sales_order_id) {
            $record->loadMissing('salesOrder');
            $so = $record->salesOrder;

            $groups[] = [
                'label' => 'Sales Order',
                'items' => [[
                    'number' => $so->so_number,
                    'status' => $so->status->getLabel(),
                    'color' => match ($so->status) {
                        SalesOrderStatus::Confirmed, SalesOrderStatus::Delivered => 'success',
                        SalesOrderStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => SalesOrderResource::getUrl('view', ['record' => $so]),
                ]],
            ];
        }

        $record->loadMissing('salesReturns');

        if ($record->salesReturns->isNotEmpty()) {
            $groups[] = [
                'label' => 'Sales Returns',
                'items' => $record->salesReturns->map(fn ($sr) => [
                    'number' => $sr->sr_number,
                    'status' => $sr->status->getLabel(),
                    'color' => match ($sr->status) {
                        SalesReturnStatus::Confirmed => 'success',
                        SalesReturnStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => SalesReturnResource::getUrl('view', ['record' => $sr]),
                ])->toArray(),
            ];
        }

        return $groups;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (DeliveryNote $record): bool => $record->isEditable()),

            Action::make('confirm_delivery')
                ->label('Confirm Delivery')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Delivery')
                ->modalDescription('This will issue reserved stock from the warehouse for all items. This action cannot be undone.')
                ->visible(fn (DeliveryNote $record): bool => $record->status === DeliveryNoteStatus::Draft)
                ->action(function (DeliveryNote $record): void {
                    try {
                        app(DeliveryNoteService::class)->confirm($record);
                        Notification::make()
                            ->title('Delivery confirmed successfully')
                            ->success()
                            ->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot confirm delivery')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } catch (InsufficientStockException $e) {
                        Notification::make()
                            ->title('Insufficient stock')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('print_delivery_note')
                ->label('Print Delivery Note')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (DeliveryNote $record): bool => $record->status === DeliveryNoteStatus::Confirmed)
                ->action(function (DeliveryNote $record) {
                    $record->loadMissing(['partner', 'warehouse', 'items.productVariant.product']);

                    return response()->streamDownload(
                        function () use ($record) {
                            $pdf = Pdf::loadView('pdf.delivery-note', ['deliveryNote' => $record]);
                            echo $pdf->output();
                        },
                        "delivery-note-{$record->dn_number}.pdf"
                    );
                }),

            Action::make('create_return')
                ->label('Create Sales Return')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->visible(fn (DeliveryNote $record): bool => $record->status === DeliveryNoteStatus::Confirmed)
                ->url(fn (DeliveryNote $record): string => SalesReturnResource::getUrl('create').'?delivery_note_id='.$record->id),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (DeliveryNote $record): bool => $record->status === DeliveryNoteStatus::Draft)
                ->action(function (DeliveryNote $record): void {
                    app(DeliveryNoteService::class)->cancel($record);
                    Notification::make()->title('Delivery note cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
