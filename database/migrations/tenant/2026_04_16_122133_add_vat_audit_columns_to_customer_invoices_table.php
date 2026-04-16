<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->string('vat_scenario')->nullable()->after('is_reverse_charge')->index();
            $table->string('vies_request_id')->nullable()->after('vat_scenario');
            $table->timestamp('vies_checked_at')->nullable()->after('vies_request_id');
            $table->string('vies_result')->nullable()->after('vies_checked_at');
            $table->boolean('reverse_charge_manual_override')->default(false)->after('vies_result');
            $table->unsignedBigInteger('reverse_charge_override_user_id')->nullable()->after('reverse_charge_manual_override');
            $table->timestamp('reverse_charge_override_at')->nullable()->after('reverse_charge_override_user_id');
            $table->string('reverse_charge_override_reason')->nullable()->after('reverse_charge_override_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropIndex(['vat_scenario']);
            $table->dropColumn([
                'vat_scenario',
                'vies_request_id',
                'vies_checked_at',
                'vies_result',
                'reverse_charge_manual_override',
                'reverse_charge_override_user_id',
                'reverse_charge_override_at',
                'reverse_charge_override_reason',
            ]);
        });
    }
};
