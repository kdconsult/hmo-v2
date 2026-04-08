<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();

            // Business identity
            $table->string('name');
            $table->string('slug')->unique(); // subdomain
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Address
            $table->string('address_line_1')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('BG');

            // Bulgarian/EU identifiers
            $table->string('vat_number')->nullable();
            $table->string('eik')->nullable(); // Bulgarian EIK/BULSTAT
            $table->string('mol')->nullable(); // Materially Responsible Person

            // Branding
            $table->string('logo_path')->nullable();

            // Localization
            $table->string('locale', 10)->default('bg');
            $table->string('timezone')->default('Europe/Sofia');
            $table->string('default_currency_code', 3)->default('BGN');

            // Subscription
            $table->string('subscription_plan')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
