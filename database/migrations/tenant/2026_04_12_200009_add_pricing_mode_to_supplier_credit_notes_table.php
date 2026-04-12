<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_credit_notes', function (Blueprint $table): void {
            $table->string('pricing_mode')->default('vat_exclusive')->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_credit_notes', function (Blueprint $table): void {
            $table->dropColumn('pricing_mode');
        });
    }
};
