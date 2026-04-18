<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->string('exchange_rate_source', 10)->nullable()->after('exchange_rate');
            $table->char('document_hash', 64)->nullable()->after('status');
        });

        Schema::table('customer_credit_notes', function (Blueprint $table): void {
            $table->string('exchange_rate_source', 10)->nullable()->after('exchange_rate');
            $table->char('document_hash', 64)->nullable()->after('status');
        });

        Schema::table('customer_debit_notes', function (Blueprint $table): void {
            $table->string('exchange_rate_source', 10)->nullable()->after('exchange_rate');
            $table->char('document_hash', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate_source', 'document_hash']);
        });

        Schema::table('customer_credit_notes', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate_source', 'document_hash']);
        });

        Schema::table('customer_debit_notes', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate_source', 'document_hash']);
        });
    }
};
