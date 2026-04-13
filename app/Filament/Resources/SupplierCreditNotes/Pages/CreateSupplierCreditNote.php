<?php

namespace App\Filament\Resources\SupplierCreditNotes\Pages;

use App\Enums\SeriesType;
use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use App\Models\NumberSeries;
use App\Models\SupplierInvoice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateSupplierCreditNote extends CreateRecord
{
    protected static string $resource = SupplierCreditNoteResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($invoiceId = request()->query('supplier_invoice_id')) {
            $invoice = SupplierInvoice::find($invoiceId);
            if ($invoice) {
                $this->form->fill([
                    'supplier_invoice_id' => $invoice->id,
                    'partner_id' => $invoice->partner_id,
                    'currency_code' => $invoice->currency_code,
                    'exchange_rate' => $invoice->exchange_rate,
                    'pricing_mode' => $invoice->pricing_mode->value,
                    'issued_at' => now()->toDateString(),
                ]);
            }
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['credit_note_number'])) {
            $series = NumberSeries::getDefault(SeriesType::SupplierCreditNote);
            if (! $series) {
                throw ValidationException::withMessages([
                    'credit_note_number' => 'No active number series configured for Supplier Credit Notes. Go to Settings → Number Series.',
                ]);
            }
            $data['document_series_id'] = $series->id;
            $data['credit_note_number'] = $series->generateNumber();
        }

        // Safety net: ensure partner_id is set from the invoice if not already populated
        if (empty($data['partner_id']) && ! empty($data['supplier_invoice_id'])) {
            $invoice = SupplierInvoice::find($data['supplier_invoice_id']);
            if ($invoice) {
                $data['partner_id'] = $invoice->partner_id;
            }
        }

        $data['created_by'] = Auth::id();

        return $data;
    }
}
