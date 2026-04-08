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
            $table->string('status')->default('active')->index()->after('data');
            $table->timestamp('deactivated_at')->nullable()->after('status');
            $table->timestamp('marked_for_deletion_at')->nullable()->after('deactivated_at');
            $table->timestamp('scheduled_for_deletion_at')->nullable()->after('marked_for_deletion_at');
            $table->timestamp('deletion_scheduled_for')->nullable()->after('scheduled_for_deletion_at');
            $table->string('deactivation_reason')->nullable()->after('deletion_scheduled_for');
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete()->after('deactivation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['deactivated_by']);
            $table->dropColumn([
                'status',
                'deactivated_at',
                'marked_for_deletion_at',
                'scheduled_for_deletion_at',
                'deletion_scheduled_for',
                'deactivation_reason',
                'deactivated_by',
            ]);
        });
    }
};
