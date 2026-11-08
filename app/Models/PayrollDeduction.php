<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deduction taken off a payroll report by hand, with its reason: a cash
 * advance, a breakage, a uniform. Attendance never produces these, so
 * regenerating the report from attendance keeps them.
 */
class PayrollDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_entry_id',
        'amount',
        'reason',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payrollEntry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
