<?php

namespace App\Console\Commands;

use App\Mail\TenantDeletedMail;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeleteScheduledTenantsCommand extends Command
{
    protected $signature = 'hmo:delete-scheduled-tenants';

    protected $description = 'Permanently delete tenants whose deletion date has passed.';

    public function handle(): int
    {
        $landlordTenantId = config('hmo.landlord_tenant_id');

        $query = Tenant::scheduledForDeletion()->dueForDeletion();

        if ($landlordTenantId) {
            $query->where('id', '!=', $landlordTenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants due for deletion.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            // Capture scalars before deletion so the mailable is not holding a deleted model
            $email = $tenant->email;
            $name = $tenant->name;

            try {
                $this->line("Deleting tenant [{$tenant->id}] ({$name})...");
                $tenant->delete();
                $deleted++;
                $this->info('  Deleted.');

                if ($email) {
                    Mail::to($email)->queue(new TenantDeletedMail($name));
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. Deleted: {$deleted}, Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
