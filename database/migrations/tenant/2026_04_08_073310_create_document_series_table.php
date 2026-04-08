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
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();
            $table->string('document_type'); // DocumentType enum value
            $table->string('name');
            $table->string('prefix')->nullable();
            $table->string('separator')->default('-');
            $table->boolean('include_year')->default(true);
            $table->string('year_format', 4)->default('Y');
            $table->unsignedInteger('padding')->default(5);
            $table->unsignedInteger('next_number')->default(1);
            $table->boolean('reset_yearly')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_type', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
