<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\VatRateSeeder;
use Illuminate\Database\Seeder;

class TenantOnboardingService
{
    /**
     * Seed the tenant database and create the owner TenantUser.
     *
     * Runs all setup inside the tenant context so seeders write to the correct DB.
     */
    public function onboard(Tenant $tenant, User $ownerUser): void
    {
        $tenant->run(function () use ($ownerUser) {
            $this->runSeeder(RolesAndPermissionsSeeder::class);
            $this->runSeeder(CurrencySeeder::class);
            $this->runSeeder(VatRateSeeder::class);

            // Create the TenantUser for the owner if it doesn't exist yet
            TenantUser::firstOrCreate(
                ['user_id' => $ownerUser->id],
                ['user_id' => $ownerUser->id],
            );

            // Assign admin role
            $tenantUser = TenantUser::where('user_id', $ownerUser->id)->first();
            if ($tenantUser && ! $tenantUser->hasRole('admin')) {
                $tenantUser->assignRole('admin');
            }
        });
    }

    /** @param class-string<Seeder> $seederClass */
    private function runSeeder(string $seederClass): void
    {
        /** @var Seeder $seeder */
        $seeder = app($seederClass);
        $seeder->run();
    }
}
