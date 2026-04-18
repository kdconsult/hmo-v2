<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'customer_invoices',
        'customer_credit_notes',
        'customer_debit_notes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('vat_scenario_sub_code')->nullable()->after('vat_scenario');
            });

            // Credit/Debit note tables don't carry `vat_scenario` yet (added later
            // by invoice-credit-debit.md). Only back-fill rows where the source
            // column exists — on customer_invoices today, on the notes once that
            // task ships. Re-running stays safe via the `whereNull` guard.
            if (! Schema::hasColumn($table, 'vat_scenario')) {
                continue;
            }

            DB::table($table)
                ->where('vat_scenario', 'exempt')
                ->whereNull('vat_scenario_sub_code')
                ->update(['vat_scenario_sub_code' => 'default']);

            DB::table($table)
                ->where('vat_scenario', 'eu_b2b_reverse_charge')
                ->whereNull('vat_scenario_sub_code')
                ->update(['vat_scenario_sub_code' => 'goods']);

            DB::table($table)
                ->where('vat_scenario', 'non_eu_export')
                ->whereNull('vat_scenario_sub_code')
                ->update(['vat_scenario_sub_code' => 'goods']);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('vat_scenario_sub_code');
            });
        }
    }
};
