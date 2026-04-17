<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal references foundation — Phase A of the VAT/VIES feature.
 * Stores country+scenario+sub_code → (citation, translatable description) rows
 * used by the invoice PDF, credit/debit note PDF, blocks, and domestic-exempt
 * features to render the correct Art. 226 / чл. 114 legal-basis line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_legal_references', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2)->index();
            $table->string('vat_scenario')->index();
            $table->string('sub_code')->default('default');
            $table->string('legal_reference');
            $table->json('description');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['country_code', 'vat_scenario', 'sub_code'], 'vat_legal_refs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_legal_references');
    }
};
