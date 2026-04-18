<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reconcile any legacy rows where is_vat_registered=true but vat_number is NULL
        // before adding the constraint so the ALTER doesn't fail.
        DB::statement('UPDATE tenants SET is_vat_registered = false WHERE is_vat_registered = true AND vat_number IS NULL');

        DB::statement(
            'ALTER TABLE tenants ADD CONSTRAINT tenants_vat_invariant '
            .'CHECK (NOT is_vat_registered OR vat_number IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_vat_invariant');
    }
};
