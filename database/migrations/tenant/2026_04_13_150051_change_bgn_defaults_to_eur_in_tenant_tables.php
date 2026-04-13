<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('base_currency_code', 3)->default('EUR')->change();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('EUR')->change();
        });

        Schema::table('partner_bank_accounts', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('EUR')->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('base_currency_code', 3)->default('BGN')->change();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('BGN')->change();
        });

        Schema::table('partner_bank_accounts', function (Blueprint $table) {
            $table->string('currency_code', 3)->default('BGN')->change();
        });
    }
};
