<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F-030: partners.country_code must never be null.
     *
     * Before the enum change in VatScenario::determine() lands, null country_code
     * silently routed invoices to NonEuExport (0% VAT). To prevent that path from
     * ever being writable again:
     *  1. Backfill existing null rows with the tenant's own country_code.
     *  2. Refuse to migrate if any null rows remain (tenant ops must resolve).
     *  3. Apply NOT NULL.
     */
    public function up(): void
    {
        $tenantCountry = strtoupper((string) (tenancy()->tenant?->country_code ?? 'BG'));

        DB::table('partners')
            ->whereNull('country_code')
            ->update(['country_code' => $tenantCountry]);

        $remaining = DB::table('partners')->whereNull('country_code')->count();
        if ($remaining > 0) {
            throw new RuntimeException(
                "Cannot apply NOT NULL to partners.country_code: {$remaining} null rows remain. ".
                'Resolve manually before re-running.'
            );
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->change();
        });
    }
};
