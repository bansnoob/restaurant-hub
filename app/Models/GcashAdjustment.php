<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A correcting entry against GCash takings — money recorded that should not have been, or a
 * reversal that never made it back through the POS.
 *
 * Deliberately not a Sale and not an Expense: it is neither revenue nor a cost, and keeping it
 * out of `sales` is what stops it invalidating a closed day's gcash_sales_total snapshot.
 */
class GcashAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'adjustment_date',
        'amount',
        'reason',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
