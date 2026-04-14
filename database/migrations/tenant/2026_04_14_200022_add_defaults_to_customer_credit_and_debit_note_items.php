<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credit_note_items', function (Blueprint $table) {
            $table->decimal('vat_amount', 15, 2)->default(0)->change();
            $table->decimal('line_total', 15, 2)->default(0)->change();
            $table->decimal('line_total_with_vat', 15, 2)->default(0)->change();
        });

        Schema::table('customer_debit_note_items', function (Blueprint $table) {
            $table->decimal('vat_amount', 15, 2)->default(0)->change();
            $table->decimal('line_total', 15, 2)->default(0)->change();
            $table->decimal('line_total_with_vat', 15, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_note_items', function (Blueprint $table) {
            $table->decimal('vat_amount', 15, 2)->default(null)->change();
            $table->decimal('line_total', 15, 2)->default(null)->change();
            $table->decimal('line_total_with_vat', 15, 2)->default(null)->change();
        });

        Schema::table('customer_debit_note_items', function (Blueprint $table) {
            $table->decimal('vat_amount', 15, 2)->default(null)->change();
            $table->decimal('line_total', 15, 2)->default(null)->change();
            $table->decimal('line_total_with_vat', 15, 2)->default(null)->change();
        });
    }
};
