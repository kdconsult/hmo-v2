<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('company'); // PartnerType enum
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('eik')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('mol')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('secondary_phone')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->string('default_currency_code', 3)->nullable();
            $table->unsignedInteger('default_payment_term_days')->nullable();
            $table->string('default_payment_method')->nullable(); // PaymentMethod enum
            $table->foreignId('default_vat_rate_id')->nullable()->constrained('vat_rates')->nullOnDelete();
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_customer', 'is_supplier']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
