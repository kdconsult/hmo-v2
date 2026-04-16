<?php

use App\Support\EuCountries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('is_vat_registered')->default(false)->after('vat_number');
            $table->string('vat_status')->default('not_registered')->after('is_vat_registered');
            $table->timestamp('vies_verified_at')->nullable()->after('vat_status');
            $table->timestamp('vies_last_checked_at')->nullable()->after('vies_verified_at');
        });

        // Backfill: partners with a stored VAT number in an EU country are treated as confirmed
        $euCodes = EuCountries::codes();
        $placeholders = implode(',', array_fill(0, count($euCodes), '?'));

        DB::statement(
            "UPDATE partners
             SET vat_status = 'confirmed',
                 is_vat_registered = true,
                 vies_verified_at = NOW()
             WHERE vat_number IS NOT NULL
               AND vat_number != ''
               AND country_code IN ({$placeholders})",
            $euCodes
        );
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['is_vat_registered', 'vat_status', 'vies_verified_at', 'vies_last_checked_at']);
        });
    }
};
