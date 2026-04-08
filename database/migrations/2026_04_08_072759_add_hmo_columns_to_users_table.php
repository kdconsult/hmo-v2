<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('name');
            $table->string('locale', 10)->nullable()->after('avatar_path');
            $table->boolean('is_landlord')->default(false)->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('is_landlord');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'locale', 'is_landlord', 'last_login_at']);
        });
    }
};
