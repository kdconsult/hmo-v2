<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('pr_number')->unique();
            $table->foreignId('document_series_id')->nullable()->constrained('number_series')->nullOnDelete();
            $table->foreignId('goods_received_note_id')->nullable()->constrained('goods_received_notes')->nullOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->date('returned_at')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('partner_id');
            $table->index('warehouse_id');
            $table->index('goods_received_note_id');
            $table->index('returned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
