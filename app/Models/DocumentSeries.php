<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class DocumentSeries extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'document_type',
        'name',
        'prefix',
        'separator',
        'include_year',
        'year_format',
        'padding',
        'next_number',
        'reset_yearly',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'include_year' => 'boolean',
            'reset_yearly' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'padding' => 'integer',
            'next_number' => 'integer',
        ];
    }

    /**
     * Generate the next document number atomically.
     * Uses a DB-level lock to prevent race conditions.
     */
    public function generateNumber(): string
    {
        return DB::transaction(function () {
            /** @var self $series */
            $series = static::lockForUpdate()->findOrFail($this->id);

            $number = $series->next_number;
            $series->increment('next_number');

            return $this->formatNumber($number, $series);
        });
    }

    private function formatNumber(int $number, self $series): string
    {
        $parts = [];

        if ($series->prefix) {
            $parts[] = $series->prefix;
        }

        if ($series->include_year) {
            $parts[] = now()->format($series->year_format);
        }

        $parts[] = str_pad((string) $number, $series->padding, '0', STR_PAD_LEFT);

        return implode($series->separator, $parts);
    }

    public static function getDefault(DocumentType $type): ?self
    {
        return static::where('document_type', $type->value)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }
}
