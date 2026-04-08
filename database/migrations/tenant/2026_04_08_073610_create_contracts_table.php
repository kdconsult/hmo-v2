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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number')->unique();
            $table->foreignId('document_series_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft'); // ContractStatus enum
            $table->string('type'); // maintenance, sla, subscription
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->decimal('monthly_fee', 15, 2)->nullable();
            $table->string('currency_code', 3)->default('BGN');
            $table->decimal('included_hours', 8, 2)->nullable();
            $table->decimal('included_materials_budget', 15, 2)->nullable();
            $table->decimal('used_hours', 8, 2)->default(0);
            $table->decimal('used_materials', 15, 2)->default(0);
            $table->unsignedInteger('billing_day')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('partner_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
