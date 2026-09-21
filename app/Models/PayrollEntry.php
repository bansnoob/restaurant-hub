<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_period_id',
        'employee_id',
        'regular_hours',
        'overtime_hours',
        'hourly_rate',
        'daily_rate',
        'gross_pay',
        'deductions',
        'net_pay',
        'status',
        'notes',
    ];

    /**
     * The overhead row posted when this report was finalized, if it has been.
     */
    public function specialExpense(): HasOne
    {
        return $this->hasOne(SpecialExpense::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }
}
