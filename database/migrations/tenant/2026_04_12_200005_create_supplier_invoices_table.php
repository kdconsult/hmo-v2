<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_invoice_number');
            $table->string('internal_number')->unique();
            $table->foreignId('document_series_id')->nullable()->constrained('number_series')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->string('currency_code', 3)->default('EUR');
            $table->decimal('exchange_rate', 16, 6)->default(1.000000);
            $table->string('pricing_mode')->default('vat_exclusive');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('amount_due', 15, 2)->default(0);
            $table->date('issued_at');
            $table->date('received_at')->nullable();
            $table->date('due_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['partner_id', 'supplier_invoice_number']);
            $table->index('status');
            $table->index('partner_id');
            $table->index('purchase_order_id');
            $table->index('due_date');
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
