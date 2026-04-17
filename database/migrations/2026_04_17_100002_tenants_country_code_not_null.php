<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F-030: tenants.country_code must never be null.
     *
     * The column already has default('BG') since the original create_tenants
     * migration, so new rows never get null. This migration enforces the
     * invariant at the schema level and fails loudly if a legacy null exists.
     */
    public function up(): void
    {
        $remaining = DB::table('tenants')->whereNull('country_code')->count();
        if ($remaining > 0) {
            throw new RuntimeException(
                "Cannot apply NOT NULL to tenants.country_code: {$remaining} null rows remain. ".
                'Resolve manually before re-running.'
            );
        }

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable(false)->default('BG')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('country_code', 2)->nullable()->default('BG')->change();
        });
    }
};
