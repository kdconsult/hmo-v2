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
        'number_series',
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
        // Phase 3.1 — Purchases
        'purchase_order',
        'purchase_order_item',
        'goods_received_note',
        'goods_received_note_item',
        'supplier_invoice',
        'supplier_invoice_item',
        'supplier_credit_note',
        'supplier_credit_note_item',
        // Phase 3.1 — Purchase Returns
        'purchase_return',
        'purchase_return_item',
        // Phase 3.2 — Sales / Invoicing
        'quotation',
        'quotation_item',
        'sales_order',
        'sales_order_item',
        'delivery_note',
        'delivery_note_item',
        'customer_invoice',
        'customer_invoice_item',
        'customer_credit_note',
        'customer_credit_note_item',
        'customer_debit_note',
        'customer_debit_note_item',
        'sales_return',
        'sales_return_item',
        'advance_payment',
        'advance_payment_application',
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

        // sales-manager — full CRUD on all sales documents + view catalog/warehouse/partners
        $salesManager = Role::firstOrCreate(['name' => 'sales-manager']);
        $salesManager->syncPermissions([
            // Full CRUD on all sales documents
            'view_any_quotation', 'view_quotation', 'create_quotation', 'update_quotation', 'delete_quotation',
            'view_any_quotation_item', 'view_quotation_item', 'create_quotation_item', 'update_quotation_item', 'delete_quotation_item',
            'view_any_sales_order', 'view_sales_order', 'create_sales_order', 'update_sales_order', 'delete_sales_order',
            'view_any_sales_order_item', 'view_sales_order_item', 'create_sales_order_item', 'update_sales_order_item', 'delete_sales_order_item',
            'view_any_delivery_note', 'view_delivery_note', 'create_delivery_note', 'update_delivery_note', 'delete_delivery_note',
            'view_any_delivery_note_item', 'view_delivery_note_item', 'create_delivery_note_item', 'update_delivery_note_item', 'delete_delivery_note_item',
            'view_any_customer_invoice', 'view_customer_invoice', 'create_customer_invoice', 'update_customer_invoice', 'delete_customer_invoice',
            'view_any_customer_invoice_item', 'view_customer_invoice_item', 'create_customer_invoice_item', 'update_customer_invoice_item', 'delete_customer_invoice_item',
            'view_any_customer_credit_note', 'view_customer_credit_note', 'create_customer_credit_note', 'update_customer_credit_note', 'delete_customer_credit_note',
            'view_any_customer_credit_note_item', 'view_customer_credit_note_item', 'create_customer_credit_note_item', 'update_customer_credit_note_item', 'delete_customer_credit_note_item',
            'view_any_customer_debit_note', 'view_customer_debit_note', 'create_customer_debit_note', 'update_customer_debit_note', 'delete_customer_debit_note',
            'view_any_customer_debit_note_item', 'view_customer_debit_note_item', 'create_customer_debit_note_item', 'update_customer_debit_note_item', 'delete_customer_debit_note_item',
            'view_any_sales_return', 'view_sales_return', 'create_sales_return', 'update_sales_return', 'delete_sales_return',
            'view_any_sales_return_item', 'view_sales_return_item', 'create_sales_return_item', 'update_sales_return_item', 'delete_sales_return_item',
            'view_any_advance_payment', 'view_advance_payment', 'create_advance_payment', 'update_advance_payment', 'delete_advance_payment',
            'view_any_advance_payment_application', 'view_advance_payment_application', 'create_advance_payment_application', 'update_advance_payment_application', 'delete_advance_payment_application',
            // View-only on catalog, warehouse, partners
            'view_any_partner', 'view_partner',
            'view_any_product', 'view_product',
            'view_any_product_variant', 'view_product_variant',
            'view_any_category', 'view_category',
            'view_any_unit', 'view_unit',
            'view_any_warehouse', 'view_warehouse',
            'view_any_stock_item', 'view_stock_item',
        ]);

        // sales-agent — CRUD partners, view contracts
        $salesAgent = Role::firstOrCreate(['name' => 'sales-agent']);
        $salesAgent->syncPermissions([
            'view_any_partner', 'view_partner', 'create_partner', 'update_partner',
            'view_any_contract', 'view_contract',
            'view_any_tag', 'view_tag',
        ]);

        // accountant — view partners/contracts, CRUD currencies/vat_rates, view POs/GRNs, CRUD supplier invoices/credit notes
        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $accountant->syncPermissions([
            'view_any_partner', 'view_partner',
            'view_any_contract', 'view_contract',
            'view_any_currency', 'view_currency', 'create_currency', 'update_currency', 'delete_currency',
            'view_any_exchange_rate', 'view_exchange_rate', 'create_exchange_rate', 'update_exchange_rate', 'delete_exchange_rate',
            'view_any_vat_rate', 'view_vat_rate', 'create_vat_rate', 'update_vat_rate', 'delete_vat_rate',
            'view_any_number_series', 'view_number_series',
            // Phase 3.1 — view POs/GRNs
            'view_any_purchase_order', 'view_purchase_order',
            'view_any_purchase_order_item', 'view_purchase_order_item',
            'view_any_goods_received_note', 'view_goods_received_note',
            'view_any_goods_received_note_item', 'view_goods_received_note_item',
            // Phase 3.1 — view purchase returns
            'view_any_purchase_return', 'view_purchase_return',
            'view_any_purchase_return_item', 'view_purchase_return_item',
            // Phase 3.1 — full CRUD on supplier invoices/credit notes
            'view_any_supplier_invoice', 'view_supplier_invoice', 'create_supplier_invoice', 'update_supplier_invoice', 'delete_supplier_invoice',
            'view_any_supplier_invoice_item', 'view_supplier_invoice_item', 'create_supplier_invoice_item', 'update_supplier_invoice_item', 'delete_supplier_invoice_item',
            'view_any_supplier_credit_note', 'view_supplier_credit_note', 'create_supplier_credit_note', 'update_supplier_credit_note', 'delete_supplier_credit_note',
            'view_any_supplier_credit_note_item', 'view_supplier_credit_note_item', 'create_supplier_credit_note_item', 'update_supplier_credit_note_item', 'delete_supplier_credit_note_item',
            // Phase 3.2 — view sales pipeline
            'view_any_quotation', 'view_quotation',
            'view_any_quotation_item', 'view_quotation_item',
            'view_any_sales_order', 'view_sales_order',
            'view_any_sales_order_item', 'view_sales_order_item',
            'view_any_delivery_note', 'view_delivery_note',
            'view_any_delivery_note_item', 'view_delivery_note_item',
            'view_any_sales_return', 'view_sales_return',
            'view_any_sales_return_item', 'view_sales_return_item',
            // Phase 3.2 — full CRUD on financial sales documents
            'view_any_customer_invoice', 'view_customer_invoice', 'create_customer_invoice', 'update_customer_invoice', 'delete_customer_invoice',
            'view_any_customer_invoice_item', 'view_customer_invoice_item', 'create_customer_invoice_item', 'update_customer_invoice_item', 'delete_customer_invoice_item',
            'view_any_customer_credit_note', 'view_customer_credit_note', 'create_customer_credit_note', 'update_customer_credit_note', 'delete_customer_credit_note',
            'view_any_customer_credit_note_item', 'view_customer_credit_note_item', 'create_customer_credit_note_item', 'update_customer_credit_note_item', 'delete_customer_credit_note_item',
            'view_any_customer_debit_note', 'view_customer_debit_note', 'create_customer_debit_note', 'update_customer_debit_note', 'delete_customer_debit_note',
            'view_any_customer_debit_note_item', 'view_customer_debit_note_item', 'create_customer_debit_note_item', 'update_customer_debit_note_item', 'delete_customer_debit_note_item',
            'view_any_advance_payment', 'view_advance_payment', 'create_advance_payment', 'update_advance_payment', 'delete_advance_payment',
            'view_any_advance_payment_application', 'view_advance_payment_application', 'create_advance_payment_application', 'update_advance_payment_application', 'delete_advance_payment_application',
        ]);

        // viewer — view all Phase 1 models
        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->syncPermissions(
            collect($allPermissions)->filter(fn ($p) => str_starts_with($p, 'view_'))->values()->all()
        );

        // warehouse-manager — full warehouse management + view catalog + full CRUD on GRNs + view POs
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
            // Phase 3.1 — full CRUD on GRNs + view POs
            'view_any_purchase_order', 'view_purchase_order',
            'view_any_purchase_order_item', 'view_purchase_order_item',
            'view_any_goods_received_note', 'view_goods_received_note', 'create_goods_received_note', 'update_goods_received_note', 'delete_goods_received_note',
            'view_any_goods_received_note_item', 'view_goods_received_note_item', 'create_goods_received_note_item', 'update_goods_received_note_item', 'delete_goods_received_note_item',
            // Phase 3.1 — full CRUD on purchase returns
            'view_any_purchase_return', 'view_purchase_return', 'create_purchase_return', 'update_purchase_return', 'delete_purchase_return',
            'view_any_purchase_return_item', 'view_purchase_return_item', 'create_purchase_return_item', 'update_purchase_return_item', 'delete_purchase_return_item',
            // Phase 3.2 — view sales orders + full CRUD on delivery notes + sales returns
            'view_any_sales_order', 'view_sales_order',
            'view_any_sales_order_item', 'view_sales_order_item',
            'view_any_delivery_note', 'view_delivery_note', 'create_delivery_note', 'update_delivery_note', 'delete_delivery_note',
            'view_any_delivery_note_item', 'view_delivery_note_item', 'create_delivery_note_item', 'update_delivery_note_item', 'delete_delivery_note_item',
            'view_any_sales_return', 'view_sales_return', 'create_sales_return', 'update_sales_return', 'delete_sales_return',
            'view_any_sales_return_item', 'view_sales_return_item', 'create_sales_return_item', 'update_sales_return_item', 'delete_sales_return_item',
        ]);

        // field-technician — minimal Phase 1 access (expanded in later phases)
        Role::firstOrCreate(['name' => 'field-technician']);

        // finance-manager — expanded in Phase 2
        $financeManager = Role::firstOrCreate(['name' => 'finance-manager']);
        $financeManager->syncPermissions(
            collect($allPermissions)->filter(fn ($p) => str_starts_with($p, 'view_'))->values()->all()
        );

        // purchasing-manager — full CRUD on all purchase models + view catalog/warehouse/partners
        $purchasingManager = Role::firstOrCreate(['name' => 'purchasing-manager']);
        $purchasingManager->syncPermissions([
            // Full CRUD on all purchase documents
            'view_any_purchase_order', 'view_purchase_order', 'create_purchase_order', 'update_purchase_order', 'delete_purchase_order',
            'view_any_purchase_order_item', 'view_purchase_order_item', 'create_purchase_order_item', 'update_purchase_order_item', 'delete_purchase_order_item',
            'view_any_goods_received_note', 'view_goods_received_note', 'create_goods_received_note', 'update_goods_received_note', 'delete_goods_received_note',
            'view_any_goods_received_note_item', 'view_goods_received_note_item', 'create_goods_received_note_item', 'update_goods_received_note_item', 'delete_goods_received_note_item',
            'view_any_supplier_invoice', 'view_supplier_invoice', 'create_supplier_invoice', 'update_supplier_invoice', 'delete_supplier_invoice',
            'view_any_supplier_invoice_item', 'view_supplier_invoice_item', 'create_supplier_invoice_item', 'update_supplier_invoice_item', 'delete_supplier_invoice_item',
            'view_any_supplier_credit_note', 'view_supplier_credit_note', 'create_supplier_credit_note', 'update_supplier_credit_note', 'delete_supplier_credit_note',
            'view_any_supplier_credit_note_item', 'view_supplier_credit_note_item', 'create_supplier_credit_note_item', 'update_supplier_credit_note_item', 'delete_supplier_credit_note_item',
            // Phase 3.1 — full CRUD on purchase returns
            'view_any_purchase_return', 'view_purchase_return', 'create_purchase_return', 'update_purchase_return', 'delete_purchase_return',
            'view_any_purchase_return_item', 'view_purchase_return_item', 'create_purchase_return_item', 'update_purchase_return_item', 'delete_purchase_return_item',
            // View-only on catalog, warehouse, partners
            'view_any_partner', 'view_partner',
            'view_any_product', 'view_product',
            'view_any_product_variant', 'view_product_variant',
            'view_any_category', 'view_category',
            'view_any_unit', 'view_unit',
            'view_any_warehouse', 'view_warehouse',
            'view_any_stock_item', 'view_stock_item',
        ]);

        // report-viewer — view only
        $reportViewer = Role::firstOrCreate(['name' => 'report-viewer']);
        $reportViewer->syncPermissions(
            collect($allPermissions)->filter(fn ($p) => str_starts_with($p, 'view_any_'))->values()->all()
        );
    }
}
