<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_payment_id')->constrained('advance_payments')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
            $table->decimal('amount_applied', 15, 2);
            $table->datetime('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['advance_payment_id', 'customer_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_payment_applications');
    }
};
