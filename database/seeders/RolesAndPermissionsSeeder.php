<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** @var string[] */
    private array $models = [
        'partner',
        'contract',
        'currency',
        'exchange_rate',
        'vat_rate',
        'document_series',
        'tenant_user',
        'tag',
        'company_settings',
        'role',
        // Phase 2 — Catalog
        'category',
        'unit',
        'product',
        'product_variant',
        // Phase 2 — Warehouse
        'warehouse',
        'stock_location',
        'stock_item',
        'stock_movement',
    ];

    /** @var string[] */
    private array $actions = ['view_any', 'view', 'create', 'update', 'delete'];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create all permissions
        $allPermissions = [];
        foreach ($this->models as $model) {
            foreach ($this->actions as $action) {
                $permission = Permission::firstOrCreate(['name' => "{$action}_{$model}"]);
                $allPermissions[] = $permission->name;
            }
        }

        // super-admin — bypasses all gates via Gate::before
        Role::firstOrCreate(['name' => 'super-admin']);

        // admin — full CRUD on all Phase 1 models
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($allPermissions);

        // sales-manager — CRUD partners, view contracts, view catalog + stock
        $salesManager = Role::firstOrCreate(['name' => 'sales-manager']);
        $salesManager->syncPermissions([
            'view_any_partner', 'view_partner', 'create_partner', 'update_partner', 'delete_partner',
            'view_any_contract', 'view_contract',
            'view_any_tag', 'view_tag', 'create_tag', 'update_tag', 'delete_tag',
            // Phase 2 catalog view
            'view_any_product', 'view_product',
            'view_any_product_variant', 'view_product_variant',
            'view_any_category', 'view_category',
            'view_any_unit', 'view_unit',
            'view_any_stock_item', 'view_stock_item',
        ]);

        // sales-agent — CRUD partners, view contracts
        $salesAgent = Role::firstOrCreate(['name' => 'sales-agent']);
        $salesAgent->syncPermissions([
            'view_any_partner', 'view_partner', 'create_partner', 'update_partner',
            'view_any_contract', 'view_contract',
            'view_any_tag', 'view_tag',
        ]);

        // accountant — view partners/contracts, CRUD currencies/vat_rates
        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $accountant->syncPermissions([
            'view_any_partner', 'view_partner',
            'view_any_contract', 'view_contract',
            'view_any_currency', 'view_currency', 'create_currency', 'update_currency', 'delete_currency',
            'view_any_exchange_rate', 'view_exchange_rate', 'create_exchange_rate', 'update_exchange_rate', 'delete_exchange_rate',
            'view_any_vat_rate', 'view_vat_rate', 'create_vat_rate', 'update_vat_rate', 'delete_vat_rate',
            'view_any_document_series', 'view_document_series',
        ]);

        // viewer — view all Phase 1 models
        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->syncPermissions(
            collect($allPermissions)->filter(fn ($p) => str_starts_with($p, 'view_'))->values()->all()
        );

        // warehouse-manager — full warehouse management + view catalog
        $warehouseManager = Role::firstOrCreate(['name' => 'warehouse-manager']);
        $warehouseManager->syncPermissions([
            // Full CRUD on warehouse entities
            'view_any_warehouse', 'view_warehouse', 'create_warehouse', 'update_warehouse', 'delete_warehouse',
            'view_any_stock_location', 'view_stock_location', 'create_stock_location', 'update_stock_location', 'delete_stock_location',
            'view_any_stock_item', 'view_stock_item', 'create_stock_item', 'update_stock_item',
            'view_any_stock_movement', 'view_stock_movement', 'create_stock_movement',
            // View-only on catalog
            'view_any_product', 'view_product',
            'view_any_product_variant', 'view_product_variant',
            'view_any_category', 'view_category',
            'view_any_unit', 'view_unit',
        ]);

        // field-technician — minimal Phase 1 access (expanded in later phases)
        Role::firstOrCreate(['name' => 'field-technician']);

        // finance-manager — expanded in Phase 2
        $financeManager = Role::firstOrCreate(['name' => 'finance-manager']);
        $financeManager->syncPermissions(
            collect($allPermissions)->filter(fn ($p) => str_starts_with($p, 'view_'))->values()->all()
        );

        // purchasing-manager — expanded in later phases
        Role::firstOrCreate(['name' => 'purchasing-manager']);

        // report-viewer — view only
        $reportViewer = Role::firstOrCreate(['name' => 'report-viewer']);
        $reportViewer->syncPermissions(
            collect($allPermissions)->filter(fn ($p) => str_starts_with($p, 'view_any_'))->values()->all()
        );
    }
}
