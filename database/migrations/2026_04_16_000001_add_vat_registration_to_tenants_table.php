<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_vat_registered')->default(false)->after('vat_number');
            $table->timestamp('vies_verified_at')->nullable()->after('is_vat_registered');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['is_vat_registered', 'vies_verified_at']);
        });
    }
};
