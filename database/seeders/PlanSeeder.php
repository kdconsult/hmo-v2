<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'price' => 0,
                'billing_period' => null,
                'max_users' => 2,
                'max_documents' => 100,
                'features' => [
                    'invoicing' => 'true',
                    'inventory' => 'false',
                    'crm' => 'false',
                    'reports' => 'basic',
                ],
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'price' => 49.00,
                'billing_period' => 'monthly',
                'max_users' => null,
                'max_documents' => null,
                'features' => [
                    'invoicing' => 'true',
                    'inventory' => 'true',
                    'crm' => 'true',
                    'reports' => 'advanced',
                    'fiscal' => 'true',
                    'api_access' => 'true',
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
