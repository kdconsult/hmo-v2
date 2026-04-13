<?php

use App\Models\ProductVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants')) {
            return;
        }

        ProductVariant::where('is_default', true)->each(function (ProductVariant $variant) {
            $raw = $variant->getRawOriginal('name');
            if (! is_string($raw)) {
                return;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return;
            }

            foreach ($decoded as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $nested = json_decode($value, true);
                if (is_array($nested)) {
                    // Double-encoded: {"en":"{\"en\":\"...\",\"bg\":\"...\"}"}
                    $variant->setTranslations('name', $nested);
                    $variant->save();

                    return;
                }
            }
        });
    }

    public function down(): void
    {
        // Forward-fix migration — not reversible
    }
};
