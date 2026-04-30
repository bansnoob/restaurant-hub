<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_count_id',
        'ingredient_id',
        'previous_quantity',
        'restocked_quantity',
        'counted_quantity',
        'consumption',
        'unit_cost',
        'line_value',
    ];

    protected $casts = [
        'previous_quantity' => 'decimal:3',
        'restocked_quantity' => 'decimal:3',
        'counted_quantity' => 'decimal:3',
        'consumption' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'line_value' => 'decimal:2',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
