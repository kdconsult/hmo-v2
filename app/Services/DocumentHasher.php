<?php

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\ExchangeRate;

/**
 * Computes and verifies SHA-256 hashes for confirmed financial documents.
 * Canonical payloads pin the document's economic state at confirmation time.
 */
class DocumentHasher
{
    public static function forInvoice(CustomerInvoice $invoice): string
    {
        $invoice->loadMissing('items');

        return hash('sha256', json_encode(self::canonicalize(self::invoicePayload($invoice)), JSON_UNESCAPED_UNICODE));
    }

    public static function forCreditNote(CustomerCreditNote $note, CustomerInvoice $parent): string
    {
        $note->loadMissing('items');

        return hash('sha256', json_encode(self::canonicalize(self::creditNotePayload($note, $parent)), JSON_UNESCAPED_UNICODE));
    }

    public static function forDebitNote(CustomerDebitNote $note, ?CustomerInvoice $parent): string
    {
        $note->loadMissing('items');

        return hash('sha256', json_encode(self::canonicalize(self::debitNotePayload($note, $parent)), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Look up the source label from the ExchangeRate row for this currency + date.
     * Falls back to 'manual' when no row exists (rate was entered by hand).
     * Always returns 'fixed_eur' for EUR-denominated documents.
     */
    public static function resolveExchangeRateSource(string $currencyCode, \DateTimeInterface $date): string
    {
        if (strtoupper($currencyCode) === 'EUR') {
            return 'fixed_eur';
        }

        return ExchangeRate::whereHas('currency', fn ($q) => $q->where('code', $currencyCode))
            ->whereDate('date', $date->format('Y-m-d'))
            ->value('source') ?? 'manual';
    }

    private static function invoicePayload(CustomerInvoice $invoice): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'supplied_at' => $invoice->supplied_at?->toIso8601String(),
            'partner_id' => $invoice->partner_id,
            'vat_scenario' => $invoice->vat_scenario?->value,
            'vat_scenario_sub_code' => $invoice->vat_scenario_sub_code,
            'is_reverse_charge' => $invoice->is_reverse_charge,
            'vies_request_id' => $invoice->vies_request_id,
            'vies_checked_at' => $invoice->vies_checked_at?->toIso8601String(),
            'subtotal' => (string) $invoice->subtotal,
            'tax_amount' => (string) $invoice->tax_amount,
            'total' => (string) $invoice->total,
            'currency_code' => $invoice->currency_code,
            'exchange_rate' => (string) $invoice->exchange_rate,
            'items' => $invoice->items->sortBy('id')->values()->map(fn ($i) => [
                'id' => $i->id,
                'product_variant_id' => $i->product_variant_id,
                'quantity' => (string) $i->quantity,
                'unit_price' => (string) $i->unit_price,
                'vat_rate_id' => $i->vat_rate_id,
                'line_total_with_vat' => (string) $i->line_total_with_vat,
            ])->all(),
        ];
    }

    private static function creditNotePayload(CustomerCreditNote $note, CustomerInvoice $parent): array
    {
        return [
            'credit_note_number' => $note->credit_note_number,
            'issued_at' => $note->issued_at?->toIso8601String(),
            'partner_id' => $note->partner_id,
            'parent_invoice_number' => $parent->invoice_number,
            'parent_document_hash' => $parent->document_hash,
            'vat_scenario' => $note->vat_scenario?->value,
            'vat_scenario_sub_code' => $note->vat_scenario_sub_code,
            'is_reverse_charge' => $note->is_reverse_charge,
            'subtotal' => (string) $note->subtotal,
            'tax_amount' => (string) $note->tax_amount,
            'total' => (string) $note->total,
            'currency_code' => $note->currency_code,
            'exchange_rate' => (string) $note->exchange_rate,
            'items' => $note->items->sortBy('id')->values()->map(fn ($i) => [
                'id' => $i->id,
                'customer_invoice_item_id' => $i->customer_invoice_item_id,
                'product_variant_id' => $i->product_variant_id,
                'quantity' => (string) $i->quantity,
                'unit_price' => (string) $i->unit_price,
                'vat_rate_id' => $i->vat_rate_id,
                'line_total_with_vat' => (string) $i->line_total_with_vat,
            ])->all(),
        ];
    }

    private static function debitNotePayload(CustomerDebitNote $note, ?CustomerInvoice $parent): array
    {
        return [
            'debit_note_number' => $note->debit_note_number,
            'issued_at' => $note->issued_at?->toIso8601String(),
            'partner_id' => $note->partner_id,
            'parent_invoice_number' => $parent?->invoice_number,
            'parent_document_hash' => $parent?->document_hash,
            'vat_scenario' => $note->vat_scenario?->value,
            'vat_scenario_sub_code' => $note->vat_scenario_sub_code,
            'is_reverse_charge' => $note->is_reverse_charge,
            'subtotal' => (string) $note->subtotal,
            'tax_amount' => (string) $note->tax_amount,
            'total' => (string) $note->total,
            'currency_code' => $note->currency_code,
            'exchange_rate' => (string) $note->exchange_rate,
            'items' => $note->items->sortBy('id')->values()->map(fn ($i) => [
                'id' => $i->id,
                'customer_invoice_item_id' => $i->customer_invoice_item_id,
                'product_variant_id' => $i->product_variant_id,
                'quantity' => (string) $i->quantity,
                'unit_price' => (string) $i->unit_price,
                'vat_rate_id' => $i->vat_rate_id,
                'line_total_with_vat' => (string) $i->line_total_with_vat,
            ])->all(),
        ];
    }

    private static function canonicalize(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::canonicalize($v);
            }
        }

        return $data;
    }
}
