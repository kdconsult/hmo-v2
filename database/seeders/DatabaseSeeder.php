<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Central DB: create landlord admin user
        $landlord = User::updateOrCreate(
            ['email' => 'admin@hmo.localhost'],
            [
                'name' => 'HMO Landlord',
                'password' => bcrypt('password'),
                'is_landlord' => true,
            ]
        );

        // Create a demo tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Demo Company BG',
                'email' => 'demo@hmo.localhost',
                'country_code' => 'BG',
                'locale' => 'bg',
                'timezone' => 'Europe/Sofia',
                'default_currency_code' => 'BGN',
                'eik' => '123456789',
            ]
        );

        // Create tenant admin user
        $tenantAdmin = User::updateOrCreate(
            ['email' => 'tenant-admin@hmo.localhost'],
            [
                'name' => 'Demo Admin',
                'password' => bcrypt('password'),
            ]
        );

        // Attach tenant admin to the demo tenant
        $tenant->users()->syncWithoutDetaching([$tenantAdmin->id]);

        // Seed tenant-specific data
        tenancy()->initialize($tenant);

        $this->call([
            RolesAndPermissionsSeeder::class,
            CurrencySeeder::class,
            VatRateSeeder::class,
        ]);

        tenancy()->end();
    }
}
