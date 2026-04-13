<?php

namespace App\Filament\Resources\SupplierInvoices\Pages;

use App\Enums\DocumentStatus;
use App\Enums\GoodsReceivedNoteStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\GoodsReceivedNotes\GoodsReceivedNoteResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\CompanySettings;
use App\Models\SupplierInvoice;
use App\Models\Warehouse;
use App\Services\SupplierInvoiceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ViewSupplierInvoice extends ViewRecord
{
    protected static string $resource = SupplierInvoiceResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        $record = $this->getRecord();
        $record->loadMissing(['purchaseOrder', 'creditNotes', 'goodsReceivedNotes']);

        $groups = [];

        if ($record->purchase_order_id) {
            $po = $record->purchaseOrder;
            $groups[] = [
                'label' => 'Purchase Order',
                'items' => [[
                    'number' => $po->po_number,
                    'status' => $po->status->value,
                    'color' => match ($po->status) {
                        PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Received => 'success',
                        PurchaseOrderStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => PurchaseOrderResource::getUrl('view', ['record' => $po]),
                ]],
            ];
        }

        if ($record->creditNotes->isNotEmpty()) {
            $groups[] = [
                'label' => 'Credit Notes',
                'items' => $record->creditNotes->map(fn ($cn) => [
                    'number' => $cn->credit_note_number,
                    'status' => $cn->status->value,
                    'color' => match ($cn->status) {
                        DocumentStatus::Confirmed, DocumentStatus::Paid => 'success',
                        DocumentStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => SupplierCreditNoteResource::getUrl('view', ['record' => $cn]),
                ])->all(),
            ];
        }

        if ($record->goodsReceivedNotes->isNotEmpty()) {
            $groups[] = [
                'label' => 'Goods Received Notes',
                'items' => $record->goodsReceivedNotes->map(fn ($grn) => [
                    'number' => $grn->grn_number,
                    'status' => $grn->status->value,
                    'color' => match ($grn->status) {
                        GoodsReceivedNoteStatus::Confirmed => 'success',
                        GoodsReceivedNoteStatus::Cancelled => 'danger',
                        default => 'warning',
                    },
                    'url' => GoodsReceivedNoteResource::getUrl('view', ['record' => $grn]),
                ])->all(),
            ];
        }

        return $groups;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (SupplierInvoice $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Invoice')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (SupplierInvoice $record): bool => $record->status === DocumentStatus::Draft)
                ->action(function (SupplierInvoice $record): void {
                    $record->status = DocumentStatus::Confirmed;
                    $record->save();
                    Notification::make()->title('Invoice confirmed')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('confirm_and_receive')
                ->label('Confirm & Receive')
                ->icon(Heroicon::OutlinedTruck)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm Invoice & Receive Goods')
                ->modalDescription('This will confirm the invoice and create a Goods Received Note to receive stock into the selected warehouse.')
                ->visible(function (SupplierInvoice $record): bool {
                    if ($record->status !== DocumentStatus::Draft) {
                        return false;
                    }

                    return (bool) CompanySettings::get('purchasing', 'express_purchasing', false);
                })
                ->schema([
                    Select::make('warehouse_id')
                        ->label('Receive into Warehouse')
                        ->options(Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => Warehouse::where('is_default', true)->value('id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (SupplierInvoice $record, array $data): void {
                    $warehouse = Warehouse::findOrFail($data['warehouse_id']);

                    try {
                        $grn = app(SupplierInvoiceService::class)->confirmAndReceive($record, $warehouse);

                        Notification::make()
                            ->title('Invoice confirmed & goods received')
                            ->body("GRN {$grn->grn_number} created.")
                            ->success()
                            ->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Cannot confirm & receive')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),

            Action::make('create_credit_note')
                ->label('Create Credit Note')
                ->icon(Heroicon::OutlinedDocumentMinus)
                ->color('warning')
                ->visible(fn (SupplierInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Confirmed,
                    DocumentStatus::Paid,
                ]))
                ->url(fn (SupplierInvoice $record): string => SupplierCreditNoteResource::getUrl('create').'?supplier_invoice_id='.$record->id
                ),

            Action::make('cancel')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (SupplierInvoice $record): bool => in_array($record->status, [
                    DocumentStatus::Draft,
                    DocumentStatus::Confirmed,
                ]))
                ->action(function (SupplierInvoice $record): void {
                    $record->status = DocumentStatus::Cancelled;
                    $record->save();
                    Notification::make()->title('Invoice cancelled')->success()->send();
                    $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
