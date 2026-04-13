<?php

namespace App\Filament\Resources\SupplierInvoices\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\NumberSeries;
use App\Models\PurchaseOrder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['internal_number'])) {
            $series = NumberSeries::getDefault(SeriesType::SupplierInvoice);
            if (! $series) {
                throw ValidationException::withMessages([
                    'internal_number' => 'No active number series configured for Supplier Invoices. Go to Settings → Number Series.',
                ]);
            }
            $data['document_series_id'] = $series->id;
            $data['internal_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
