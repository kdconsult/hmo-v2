<?php

namespace App\Providers;

use App\Listeners\StripeWebhookListener;
use App\Models\Contract;
use App\Models\GoodsReceivedNote;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Polymorphic morph map - prevents class name leakage in DB
        Relation::morphMap([
            'partner' => Partner::class,
            'contract' => Contract::class,
            'product' => Product::class,
            'product_variant' => ProductVariant::class,
            'warehouse' => Warehouse::class,
            'stock_movement' => StockMovement::class,
            // Phase 3.1 — Purchases
            'purchase_order' => PurchaseOrder::class,
            'goods_received_note' => GoodsReceivedNote::class,
            'supplier_invoice' => SupplierInvoice::class,
            'supplier_credit_note' => SupplierCreditNote::class,
            // Phase 3.1 — Purchase Returns
            'purchase_return' => PurchaseReturn::class,
        ]);

        // Explicit Stripe webhook listener registration — ensures the listener is bound
        // regardless of Laravel's auto-discovery state.
        Event::listen(WebhookReceived::class, StripeWebhookListener::class);

        // Super-admin bypasses all gates — only on the tenant panel (tenancy initialized).
        // On the landlord panel (central context), policies decide without bypass.
        Gate::before(function ($user, $ability) {
            if (tenancy()->initialized && method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
                return true;
            }
        });
    }
}
