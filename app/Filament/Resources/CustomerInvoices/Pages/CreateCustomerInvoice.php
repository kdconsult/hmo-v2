<?php

namespace App\Filament\Resources\CustomerInvoices\Pages;

use App\Enums\InvoiceType;
use App\Enums\SeriesType;
use App\Filament\Resources\CustomerInvoices\CustomerInvoiceResource;
use App\Models\NumberSeries;
use App\Models\SalesOrder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCustomerInvoice extends CreateRecord
{
    protected static string $resource = CustomerInvoiceResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($soId = request()->query('sales_order_id')) {
            $so = SalesOrder::find($soId);
            if ($so) {
                $this->form->fill([
                    'sales_order_id' => $so->id,
                    'partner_id' => $so->partner_id,
                    'invoice_type' => InvoiceType::SalesInvoice->value,
                    'currency_code' => $so->currency_code,
                    'exchange_rate' => $so->exchange_rate,
                    'pricing_mode' => $so->pricing_mode->value,
                    'issued_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['invoice_number'])) {
            $series = NumberSeries::getDefault(SeriesType::Invoice);

            if (! $series) {
                Notification::make()
                    ->title('No number series configured')
                    ->body('Go to Settings → Number Series and create one for Invoices.')
                    ->danger()
                    ->persistent()
                    ->send();

                $this->halt();
            }

            $data['document_series_id'] = $series->id;
            $data['invoice_number'] = $series->generateNumber();
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
