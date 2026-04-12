<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_credit_note_id')->constrained('supplier_credit_notes')->cascadeOnDelete();
            $table->foreignId('supplier_invoice_item_id')->constrained('supplier_invoice_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->text('description');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->foreignId('vat_rate_id')->constrained('vat_rates')->restrictOnDelete();
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->decimal('line_total_with_vat', 15, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('supplier_credit_note_id');
            $table->index('supplier_invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_note_items');
    }
};
