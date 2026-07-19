<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The starting point of a branch's GCash wallet: what was in the account on opening_date,
 * before anything recorded in this system. Entries dated before that day are treated as already
 * folded into the opening balance so switching tracking on mid-life cannot double-count.
 */
class GcashWallet extends Model
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
