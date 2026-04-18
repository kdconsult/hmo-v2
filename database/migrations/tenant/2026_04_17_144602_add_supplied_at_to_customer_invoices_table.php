<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->date('supplied_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->dropColumn('supplied_at');
        });
    }
};
