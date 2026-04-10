<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed central plans first
        $this->call(PlanSeeder::class);

        $freePlan = Plan::where('slug', 'free')->firstOrFail();

        // 2. Create landlord admin
        $landlord = User::updateOrCreate(
            ['email' => 'admin@hmo.localhost'],
            [
                'name' => 'HMO Landlord',
                'password' => bcrypt('password'),
                'is_landlord' => true,
            ]
        );

        // 3. Create tenant admin user (central DB)
        $tenantAdmin = User::updateOrCreate(
            ['email' => 'tenant-admin@hmo.localhost'],
            [
                'name' => 'Demo Admin',
                'password' => bcrypt('password'),
            ]
        );

        // 4. Create demo tenant
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Demo Company',
                'email' => 'demo@hmo.localhost',
                'country_code' => 'BG',
                'locale' => 'bg_BG',
                'timezone' => 'Europe/Sofia',
                'default_currency_code' => 'EUR',
                'eik' => '123456789',
                'plan_id' => $freePlan->id,
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
            ]
        );

        // 5. Attach tenant admin to tenant pivot (central)
        $tenant->users()->syncWithoutDetaching([$tenantAdmin->id]);

        // 6. Create domain for the demo tenant
        $tenant->domains()->updateOrCreate(
            ['domain' => 'demo'],
        );

        // 7. Onboard the tenant (seeds tenant DB, creates TenantUser)
        app(TenantOnboardingService::class)->onboard($tenant, $tenantAdmin);

        // 8. Create landlord's own company tenant (dogfood tenant — never billed, always Active)
        $professionalPlan = Plan::orderBy('sort_order', 'desc')->where('is_active', true)->first()
            ?? $freePlan;

        $landlordTenant = Tenant::updateOrCreate(
            ['slug' => 'landlord'],
            [
                'name' => 'Landlord Company',
                'email' => config('hmo.landlord_email', 'admin@hmo.localhost'),
                'country_code' => 'BG',
                'locale' => 'bg_BG',
                'timezone' => 'Europe/Sofia',
                'default_currency_code' => 'EUR',
                'eik' => '',
                'vat_number' => '',
                'plan_id' => $professionalPlan->id,
                'subscription_status' => SubscriptionStatus::Active,
                'subscription_ends_at' => null,
                'trial_ends_at' => null,
            ]
        );

        $landlordTenant->users()->syncWithoutDetaching([$landlord->id]);

        $landlordTenant->domains()->updateOrCreate(
            ['domain' => 'landlord'],
        );

        app(TenantOnboardingService::class)->onboard($landlordTenant, $landlord);

        $this->command->info("Landlord tenant created. Set HMO_LANDLORD_TENANT_ID={$landlordTenant->id} in .env");
    }
}
