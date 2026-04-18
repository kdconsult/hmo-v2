<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\CustomerCreditNote;
use App\Models\CustomerDebitNote;
use App\Models\CustomerInvoice;
use App\Models\Tenant;
use App\Services\DocumentHasher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hmo:integrity-check')]
#[Description('Verify document_hash integrity across confirmed documents for all tenants.')]
class IntegrityCheckCommand extends Command
{
    public function handle(): int
    {
        $totalMismatches = [];

        foreach (Tenant::all() as $tenant) {
            if ($tenant->id === config('hmo.landlord_tenant_id')) {
                continue;
            }

            $tenant->run(function () use ($tenant, &$totalMismatches): void {
                CustomerInvoice::where('status', DocumentStatus::Confirmed)
                    ->whereNotNull('document_hash')
                    ->chunk(100, function ($invoices) use ($tenant, &$totalMismatches): void {
                        foreach ($invoices as $invoice) {
                            $expected = DocumentHasher::forInvoice($invoice);

                            if ($expected !== $invoice->document_hash) {
                                $totalMismatches[] = [
                                    'type' => 'invoice',
                                    'number' => $invoice->invoice_number,
                                    'tenant' => $tenant->id,
                                ];
                            }
                        }
                    });

                CustomerCreditNote::where('status', DocumentStatus::Confirmed)
                    ->whereNotNull('document_hash')
                    ->chunk(100, function ($notes) use ($tenant, &$totalMismatches): void {
                        foreach ($notes as $note) {
                            $expected = DocumentHasher::forCreditNote($note, $note->customerInvoice);

                            if ($expected !== $note->document_hash) {
                                $totalMismatches[] = [
                                    'type' => 'credit-note',
                                    'number' => $note->credit_note_number,
                                    'tenant' => $tenant->id,
                                ];
                            }
                        }
                    });

                CustomerDebitNote::where('status', DocumentStatus::Confirmed)
                    ->whereNotNull('document_hash')
                    ->chunk(100, function ($notes) use ($tenant, &$totalMismatches): void {
                        foreach ($notes as $note) {
                            $expected = DocumentHasher::forDebitNote($note, $note->customerInvoice);

                            if ($expected !== $note->document_hash) {
                                $totalMismatches[] = [
                                    'type' => 'debit-note',
                                    'number' => $note->debit_note_number,
                                    'tenant' => $tenant->id,
                                ];
                            }
                        }
                    });
            });
        }

        if (! empty($totalMismatches)) {
            $this->error('Integrity mismatches:');
            $this->table(['Type', 'Number', 'Tenant'], $totalMismatches);

            return self::FAILURE;
        }

        $this->info('All documents pass integrity check.');

        return self::SUCCESS;
    }
}
