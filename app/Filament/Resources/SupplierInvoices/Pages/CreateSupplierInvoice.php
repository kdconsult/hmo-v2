<?php

namespace App\Filament\Resources\SupplierInvoices\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\NumberSeries;
use App\Models\PurchaseOrder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSupplierInvoice extends CreateRecord
{
    protected static string $resource = SupplierInvoiceResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($poId = request()->query('purchase_order_id')) {
            $po = PurchaseOrder::find($poId);
            if ($po) {
                $this->form->fill([
                    'purchase_order_id' => $po->id,
                    'partner_id' => $po->partner_id,
                    'currency_code' => $po->currency_code,
                    'exchange_rate' => $po->exchange_rate,
                    'pricing_mode' => $po->pricing_mode->value,
                    'issued_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function beforeCreate(): void
    {
        if (! NumberSeries::getDefault(SeriesType::SupplierInvoice)) {
            Notification::make()
                ->title('No number series configured')
                ->body('Go to Settings → Number Series and create one for Supplier Invoices.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['internal_number'])) {
            $series = NumberSeries::getDefault(SeriesType::SupplierInvoice);
            $data['document_series_id'] = $series->id;
            $data['internal_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
