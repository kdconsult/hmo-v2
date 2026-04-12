<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop index before renaming (PostgreSQL does not auto-rename indexes on table rename)
        Schema::table('document_series', function (Blueprint $table) {
            $table->dropIndex('document_series_document_type_is_default_index');
        });

        Schema::rename('document_series', 'number_series');

        Schema::table('number_series', function (Blueprint $table) {
            $table->renameColumn('document_type', 'series_type');
        });

        Schema::table('number_series', function (Blueprint $table) {
            $table->index(['series_type', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::table('number_series', function (Blueprint $table) {
            $table->dropIndex('number_series_series_type_is_default_index');
        });

        Schema::table('number_series', function (Blueprint $table) {
            $table->renameColumn('series_type', 'document_type');
        });

        Schema::rename('number_series', 'document_series');

        Schema::table('document_series', function (Blueprint $table) {
            $table->index(['document_type', 'is_default']);
        });
    }
};
