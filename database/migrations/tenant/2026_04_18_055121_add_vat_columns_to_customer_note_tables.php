<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table): void {
            $table->string('vat_scenario')->nullable()->index()->after('status');
            $table->boolean('is_reverse_charge')->default(false)->after('vat_scenario_sub_code');
            $table->date('triggering_event_date')->nullable()->after('issued_at');
        });

        Schema::table('customer_debit_notes', function (Blueprint $table): void {
            $table->string('vat_scenario')->nullable()->index()->after('status');
            $table->boolean('is_reverse_charge')->default(false)->after('vat_scenario_sub_code');
            $table->date('triggering_event_date')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table): void {
            $table->dropIndex(['vat_scenario']);
            $table->dropColumn(['vat_scenario', 'is_reverse_charge', 'triggering_event_date']);
        });

        Schema::table('customer_debit_notes', function (Blueprint $table): void {
            $table->dropIndex(['vat_scenario']);
            $table->dropColumn(['vat_scenario', 'is_reverse_charge', 'triggering_event_date']);
        });
    }
};
