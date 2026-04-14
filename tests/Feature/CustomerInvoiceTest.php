<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PricingMode;
use App\Enums\SalesOrderStatus;
use App\Events\FiscalReceiptRequested;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VatRate;
use App\Models\Warehouse;
use App\Services\CustomerInvoiceService;
use App\Services\TenantOnboardingService;
use Illuminate\Support\Facades\Event;

test('customer invoice can be created with required fields', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'subtotal' => '1000.00',
            'total' => '1200.00',
        ]);

        expect($invoice->status)->toBe(DocumentStatus::Draft)
            ->and($invoice->isEditable())->toBeTrue()
            ->and($invoice->partner_id)->toBe($partner->id);
    });
});

test('recalculateItemTotals computes correct VAT-exclusive totals', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $vatRate = VatRate::factory()->standard()->create(['rate' => '20.00']);

        $invoice = CustomerInvoice::factory()->create([
            'pricing_mode' => PricingMode::VatExclusive,
        ]);

        $item = CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'quantity' => '2.0000',
            'unit_price' => '100.0000',
            'discount_percent' => '0.00',
            'vat_rate_id' => $vatRate->id,
        ]);

        $item->setRelation('customerInvoice', $invoice);
        $item->setRelation('vatRate', $vatRate);

        app(CustomerInvoiceService::class)->recalculateItemTotals($item);
        $item->refresh();

        // qty=2, price=100 → base=200; VAT 20% → vat=40, gross=240
        expect((float) $item->line_total)->toBe(200.0)
            ->and((float) $item->vat_amount)->toBe(40.0)
            ->and((float) $item->line_total_with_vat)->toBe(240.0);
    });
});

test('recalculateDocumentTotals aggregates item line and VAT amounts', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = CustomerInvoice::factory()->create([
            'pricing_mode' => PricingMode::VatExclusive,
            'discount_amount' => '0.00',
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'line_total' => '200.00',
            'vat_amount' => '40.00',
            'line_total_with_vat' => '240.00',
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'line_total' => '300.00',
            'vat_amount' => '60.00',
            'line_total_with_vat' => '360.00',
        ]);

        app(CustomerInvoiceService::class)->recalculateDocumentTotals($invoice);
        $invoice->refresh();

        expect((float) $invoice->subtotal)->toBe(500.0)
            ->and((float) $invoice->tax_amount)->toBe(100.0)
            ->and((float) $invoice->total)->toBe(600.0);
    });
});

test('confirm sets invoice status to Confirmed', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $invoice = CustomerInvoice::factory()->create([
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        expect($invoice->status)->toBe(DocumentStatus::Confirmed);
    });
});

test('confirm updates SO qty_invoiced for linked SO items', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();

        $so = SalesOrder::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $soItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id,
            'quantity' => '5.0000',
            'qty_invoiced' => '0.0000',
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'sales_order_id' => $so->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '5.0000',
            'product_variant_id' => $soItem->product_variant_id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $soItem->refresh();
        expect((float) $soItem->qty_invoiced)->toBe(5.0);
    });
});

test('confirm transitions SO to Invoiced when all items are fully invoiced', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();

        $so = SalesOrder::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $soItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id,
            'quantity' => '3.0000',
            'qty_invoiced' => '0.0000',
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'sales_order_id' => $so->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '3.0000',
            'product_variant_id' => $soItem->product_variant_id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $so->refresh();
        expect($so->status)->toBe(SalesOrderStatus::Invoiced);
    });
});

test('confirm with service-type SO sets qty_delivered equal to qty_invoiced', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();
        $warehouse = Warehouse::factory()->create();

        $product = Product::factory()->service()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $so = SalesOrder::factory()->confirmed()->create([
            'partner_id' => $partner->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $soItem = SalesOrderItem::factory()->create([
            'sales_order_id' => $so->id,
            'product_variant_id' => $variant->id,
            'quantity' => '4.0000',
            'qty_delivered' => '0.0000',
            'qty_invoiced' => '0.0000',
        ]);

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'sales_order_id' => $so->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        CustomerInvoiceItem::factory()->create([
            'customer_invoice_id' => $invoice->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '4.0000',
            'product_variant_id' => $variant->id,
        ]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        $soItem->refresh();
        expect((float) $soItem->qty_invoiced)->toBe(4.0)
            ->and((float) $soItem->qty_delivered)->toBe(4.0);
    });
});

test('confirm dispatches FiscalReceiptRequested for cash payment', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::Cash,
        ]);

        Event::fake([FiscalReceiptRequested::class]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        Event::assertDispatched(FiscalReceiptRequested::class);
    });
});

test('confirm does not dispatch FiscalReceiptRequested for bank transfer payment', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    app(TenantOnboardingService::class)->onboard($tenant, $user);

    $tenant->run(function () {
        $partner = Partner::factory()->customer()->create();

        $invoice = CustomerInvoice::factory()->create([
            'partner_id' => $partner->id,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        Event::fake([FiscalReceiptRequested::class]);

        app(CustomerInvoiceService::class)->confirm($invoice);

        Event::assertNotDispatched(FiscalReceiptRequested::class);
    });
});
