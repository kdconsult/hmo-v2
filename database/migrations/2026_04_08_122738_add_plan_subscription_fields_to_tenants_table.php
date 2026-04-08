<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete()->after('data');
            $table->string('subscription_status')->default('trial')->after('plan_id');
            $table->timestamp('trial_ends_at')->nullable()->after('subscription_status');

            // Keep subscription_ends_at for paid subscription expiry tracking
            // Drop the old free-text subscription_plan column
            $table->dropColumn('subscription_plan');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'subscription_status', 'trial_ends_at']);
            $table->string('subscription_plan')->nullable();
        });
    }
};
