<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cash a branch was holding on opening_date, before that day's takings. Cash on Hand
 * counts forward from here; anything dated earlier is treated as already inside it.
 */
class CashOpeningBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'opening_balance',
        'opening_date',
        'updated_by_user_id',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_date' => 'date',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
