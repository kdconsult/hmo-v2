<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete()->after('customer_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropForeign(['sales_return_id']);
            $table->dropColumn('sales_return_id');
        });
    }
};
