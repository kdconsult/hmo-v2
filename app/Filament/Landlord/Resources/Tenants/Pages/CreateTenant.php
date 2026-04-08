<?php

declare(strict_types=1);

namespace App\Filament\Landlord\Resources\Tenants\Pages;

use App\Filament\Landlord\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantOnboardingService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\VatRateSeeder;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['owner_user_id']);
        $data['slug'] = Tenant::generateUniqueSlug();

        return $data;
    }

    protected function afterCreate(): void
    {
        $appDomain = last(config('tenancy.central_domains'));

        $this->record->domains()->create([
            'domain' => "{$this->record->slug}.{$appDomain}",
        ]);

        $ownerUserId = $this->form->getRawState()['owner_user_id'] ?? null;

        if ($ownerUserId) {
            $ownerUser = User::find($ownerUserId);

            if ($ownerUser) {
                // Attach to central pivot
                $this->record->users()->syncWithoutDetaching([$ownerUser->id]);

                // Seed tenant DB and create TenantUser
                app(TenantOnboardingService::class)->onboard($this->record, $ownerUser);
            }
        } else {
            // No owner — still seed the tenant DB with base data
            $this->record->run(function () {
                app(RolesAndPermissionsSeeder::class)->run();
                app(CurrencySeeder::class)->run();
                app(VatRateSeeder::class)->run();
            });
        }
    }
}
