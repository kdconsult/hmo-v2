<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\DeliveryNoteStatus;
use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SeriesType;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\NumberSeries;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ViewSalesOrder extends ViewRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected string $view = 'filament.pages.view-document-with-items';

    public function getRelatedDocuments(): array
    {
        $record = $this->getRecord();
        $record->loadMissing(['deliveryNotes', 'customerInvoices']);

        return [
            [
                'label' => 'Delivery Notes',
                'items' => $record->deliveryNotes->map(fn ($dn) => [
                    'number' => $dn->dn_number,
                    'status' => $dn->status->getLabel(),
                    'color' => $dn->status->getColor(),
                    'url' => DeliveryNoteResource::getUrl('view', ['record' => $dn]),
                ])->all(),
            ],
            [
                'label' => 'Customer Invoices',
                'items' => $record->customerInvoices->map(fn ($inv) => [
                    'number' => $inv->invoice_number,
                    'status' => $inv->status->getLabel(),
                    'color' => $inv->status->getColor(),
                    'url' => CustomerInvoiceResource::getUrl('view', ['record' => $inv]),
                ])->all(),
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (SalesOrder $record): bool => $record->isEditable()),

            Action::make('confirm')
                ->label('Confirm Order')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (SalesOrder $record): bool => $record->status === SalesOrderStatus::Draft)
                ->requiresConfirmation()
                ->modalHeading('Confirm Sales Order')
                ->modalDescription('Confirming the order will reserve stock for all stock-type items. This cannot be undone without cancelling the order.')
                ->action(function (SalesOrder $record): void {
                    try {
                        app(SalesOrderService::class)->transitionStatus($record, SalesOrderStatus::Confirmed);
                        Notification::make()->title('Order confirmed and stock reserved')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Cannot confirm order')->body($e->getMessage())->danger()->send();
                    } catch (InsufficientStockException $e) {
                        Notification::make()->title('Insufficient stock')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('create_delivery_note')
                ->label('Create Delivery Note')
                ->icon(Heroicon::OutlinedTruck)
                ->color('info')
                ->visible(fn (SalesOrder $record): bool => in_array($record->status, [
                    SalesOrderStatus::Confirmed,
                    SalesOrderStatus::PartiallyDelivered,
                ]))
                ->url(fn (SalesOrder $record): string => DeliveryNoteResource::getUrl('create').'?sales_order_id='.$record->id),

            Action::make('create_invoice')
                ->label('Create Invoice')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('warning')
                ->visible(fn (SalesOrder $record): bool => in_array($record->status, [
                    SalesOrderStatus::Confirmed,
                    SalesOrderStatus::PartiallyDelivered,
                    SalesOrderStatus::Delivered,
                ]))
                ->url(fn (SalesOrder $record): string => CustomerInvoiceResource::getUrl('create').'?sales_order_id='.$record->id),

            Action::make('import_to_po')
                ->label('Import to PO')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('gray')
                ->visible(fn (SalesOrder $record): bool => in_array($record->status, [
                    SalesOrderStatus::Confirmed,
                    SalesOrderStatus::PartiallyDelivered,
                    SalesOrderStatus::Delivered,
                ]))
                ->schema([
                    Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(
                            Partner::suppliers()->where('is_active', true)->orderBy('name')->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->live(),
                    Select::make('purchase_order_id')
                        ->label('Add to Existing PO (optional)')
                        ->options(function (Get $get): array {
                            $supplierId = $get('supplier_id');
                            $record = $this->getRecord();
                            if (! $supplierId) {
                                return [];
                            }

                            return PurchaseOrder::where('status', PurchaseOrderStatus::Draft->value)
                                ->where('partner_id', $supplierId)
                                ->where('warehouse_id', $record->warehouse_id)
                                ->orderByDesc('created_at')
                                ->get()
                                ->pluck('po_number', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->nullable()
                        ->helperText('Leave empty to create a new Purchase Order.'),
                ])
                ->action(function (SalesOrder $record, array $data): void {
                    $record->load('items');

                    if (! empty($data['purchase_order_id'])) {
                        $po = PurchaseOrder::findOrFail($data['purchase_order_id']);
                    } else {
                        $series = NumberSeries::getDefault(SeriesType::PurchaseOrder);
                        $poNumber = $series
                            ? $series->generateNumber()
                            : 'PO-'.strtoupper(Str::random(8));

                        $po = PurchaseOrder::create([
                            'po_number' => $poNumber,
                            'document_series_id' => $series?->id,
                            'partner_id' => $data['supplier_id'],
                            'warehouse_id' => $record->warehouse_id,
                            'status' => PurchaseOrderStatus::Draft->value,
                            'currency_code' => $record->currency_code,
                            'exchange_rate' => $record->exchange_rate,
                            'pricing_mode' => $record->pricing_mode->value,
                            'subtotal' => '0.00',
                            'discount_amount' => '0.00',
                            'tax_amount' => '0.00',
                            'total' => '0.00',
                            'created_by' => Auth::id(),
                        ]);
                    }

                    foreach ($record->items as $item) {
                        $po->items()->create([
                            'sales_order_item_id' => $item->id,
                            'product_variant_id' => $item->product_variant_id,
                            'description' => $item->description,
                            'quantity' => $item->quantity,
                            'quantity_received' => '0.0000',
                            'unit_price' => '0.0000',
                            'discount_percent' => '0.00',
                            'discount_amount' => '0.00',
                            'vat_rate_id' => $item->vat_rate_id,
                            'vat_amount' => '0.00',
                            'line_total' => '0.00',
                            'line_total_with_vat' => '0.00',
                            'sort_order' => $item->sort_order,
                        ]);
                    }

                    Notification::make()
                        ->title('Imported to Purchase Order')
                        ->body("SO items imported to {$po->po_number}. Update purchase prices before confirming the PO.")
                        ->success()
                        ->send();
                }),

            Action::make('cancel')
                ->label('Cancel Order')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(function (SalesOrder $record): ?string {
                    $draftDns = $record->deliveryNotes()
                        ->where('status', DeliveryNoteStatus::Draft->value)
                        ->count();
                    $draftInvoices = $record->customerInvoices()
                        ->where('status', DocumentStatus::Draft->value)
                        ->count();

                    $parts = [];

                    if ($draftDns > 0) {
                        $parts[] = "{$draftDns} draft Delivery Note".($draftDns > 1 ? 's' : '');
                    }
                    if ($draftInvoices > 0) {
                        $parts[] = "{$draftInvoices} draft Invoice".($draftInvoices > 1 ? 's' : '');
                    }

                    $base = 'Any reserved stock will be released.';

                    if (count($parts) > 0) {
                        return $base.' This will also cancel: '.implode(', ', $parts).'.';
                    }

                    return $base;
                })
                ->visible(fn (SalesOrder $record): bool => in_array($record->status, [
                    SalesOrderStatus::Draft,
                    SalesOrderStatus::Confirmed,
                    SalesOrderStatus::PartiallyDelivered,
                    SalesOrderStatus::Delivered,
                ]))
                ->action(function (SalesOrder $record): void {
                    try {
                        app(SalesOrderService::class)->transitionStatus($record, SalesOrderStatus::Cancelled);
                        Notification::make()->title('Order cancelled')->success()->send();
                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record]));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Cannot cancel order')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
