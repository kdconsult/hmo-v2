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
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('default_vat_rate_id')->nullable()->after('is_active')
                ->constrained('vat_rates')->nullOnDelete();
            $table->foreignId('default_unit_id')->nullable()->after('default_vat_rate_id')
                ->constrained('units')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_vat_rate_id');
            $table->dropConstrainedForeignId('default_unit_id');
        });
    }
};
