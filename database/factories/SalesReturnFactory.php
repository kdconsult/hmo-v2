<?php

namespace Database\Factories;

use App\Enums\SalesReturnStatus;
use App\Models\Partner;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalesReturn>
 */
class SalesReturnFactory extends Factory
{
    protected $model = SalesReturn::class;

    public function definition(): array
    {
        return [
            'sr_number' => 'SR-'.strtoupper(Str::random(8)),
            'document_series_id' => null,
            'delivery_note_id' => null,
            'partner_id' => Partner::factory()->customer(),
            'warehouse_id' => Warehouse::factory(),
            'status' => SalesReturnStatus::Draft,
            'returned_at' => now()->toDateString(),
            'reason' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => SalesReturnStatus::Draft]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => SalesReturnStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => SalesReturnStatus::Cancelled]);
    }
}
