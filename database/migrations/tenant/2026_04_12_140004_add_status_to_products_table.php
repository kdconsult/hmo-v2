<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('status')->default('active')->after('is_stockable');
        });

        // Migrate existing data: is_active=true → 'active', is_active=false → 'discontinued'
        DB::statement("UPDATE products SET status = CASE WHEN is_active THEN 'active' ELSE 'discontinued' END");

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['type', 'is_active']);
            $table->dropColumn('is_active');
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_stockable');
        });

        DB::statement("UPDATE products SET is_active = CASE WHEN status = 'active' THEN true ELSE false END");

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropColumn('status');
            $table->index(['type', 'is_active']);
        });
    }
};
