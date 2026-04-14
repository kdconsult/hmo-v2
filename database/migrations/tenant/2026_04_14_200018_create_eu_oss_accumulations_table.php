<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eu_oss_accumulations', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year')->unsigned();
            $table->char('country_code', 2);
            $table->decimal('accumulated_amount_eur', 15, 2)->default(0);
            $table->datetime('threshold_exceeded_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eu_oss_accumulations');
    }
};
