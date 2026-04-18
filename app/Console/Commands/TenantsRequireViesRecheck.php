<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hmo:tenants-require-vies-recheck')]
#[Description('Downgrade tenants with an unverified VAT number (vat_number set but vies_verified_at NULL) to is_vat_registered=false so they must re-verify via Company Settings.')]
class TenantsRequireViesRecheck extends Command
{
    public function handle(): int
    {
        $affected = Tenant::whereNotNull('vat_number')
            ->whereNull('vies_verified_at')
            ->get();

        if ($affected->isEmpty()) {
            $this->info('No tenants require a VIES re-check.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'VAT Number'], $affected->map(fn (Tenant $t) => [
            $t->id, $t->name, $t->vat_number,
        ])->toArray());

        if (! $this->confirm("Downgrade {$affected->count()} tenant(s) to is_vat_registered=false?")) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        Tenant::whereNotNull('vat_number')
            ->whereNull('vies_verified_at')
            ->update(['is_vat_registered' => false]);

        $this->info("Downgraded {$affected->count()} tenant(s). They must re-verify via Company Settings.");

        return self::SUCCESS;
    }
}
