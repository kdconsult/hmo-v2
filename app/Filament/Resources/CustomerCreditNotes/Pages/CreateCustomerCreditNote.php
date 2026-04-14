<?php

namespace App\Filament\Resources\CustomerCreditNotes\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\CustomerCreditNotes\CustomerCreditNoteResource;
use App\Models\CustomerInvoice;
use App\Models\NumberSeries;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCustomerCreditNote extends CreateRecord
{
    protected static string $resource = CustomerCreditNoteResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($invoiceId = request()->query('customer_invoice_id')) {
            $invoice = CustomerInvoice::find($invoiceId);
            if ($invoice) {
                $this->form->fill([
                    'customer_invoice_id' => $invoice->id,
                    'partner_id' => $invoice->partner_id,
                    'currency_code' => $invoice->currency_code,
                    'exchange_rate' => $invoice->exchange_rate,
                    'pricing_mode' => $invoice->pricing_mode->value,
                    'issued_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function beforeCreate(): void
    {
        if (! NumberSeries::getDefault(SeriesType::CreditNote)) {
            Notification::make()
                ->title('No number series configured')
                ->body('Go to Settings → Number Series and create one for Credit Notes.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['credit_note_number'])) {
            $series = NumberSeries::getDefault(SeriesType::CreditNote);
            $data['document_series_id'] = $series->id;
            $data['credit_note_number'] = $series->generateNumber();
        }

        // Safety net: ensure partner_id is set from the invoice if not already populated
        if (empty($data['partner_id']) && ! empty($data['customer_invoice_id'])) {
            $invoice = CustomerInvoice::find($data['customer_invoice_id']);
            if ($invoice) {
                $data['partner_id'] = $invoice->partner_id;
            }
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
