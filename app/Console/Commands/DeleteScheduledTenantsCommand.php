<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

class DeleteScheduledTenantsCommand extends Command
{
    protected $signature = 'hmo:delete-scheduled-tenants';

    protected $description = 'Permanently delete tenants whose deletion date has passed.';

    public function handle(): int
    {
        $tenants = Tenant::scheduledForDeletion()->dueForDeletion()->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants due for deletion.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $this->line("Deleting tenant [{$tenant->id}] ({$tenant->name})...");
                $tenant->delete();
                $deleted++;
                $this->info('  Deleted.');
            } catch (Throwable $e) {
                $failed++;
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. Deleted: {$deleted}, Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
