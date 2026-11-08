<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollEntry extends Model
{
    use HasFactory;

    /**
     * Attempts for a transaction that locks a report. The manual deduction total is a
     * locking read, and its gap locks can deadlock two deductions added to neighbouring
     * reports at the same moment; MySQL rolls one back, and retrying it is the answer.
     */
    public const WRITE_ATTEMPTS = 3;

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

    /**
     * Deductions taken off by hand, each with a reason. `deductions` holds these
     * and the attendance deductions together, so every total already built on it
     * (the list, the stats, the employee's history, the wage posted at finalize)
     * counts both.
     */
    public function manualDeductions(): HasMany
    {
        return $this->hasMany(PayrollDeduction::class);
    }

    /**
     * A locking read, so inside a transaction it counts a deduction committed after
     * the transaction began, and holds the rows until it ends. Callers lock the entry
     * first; a rebuild racing an add then waits for it instead of writing totals
     * that leave it out.
     */
    public function manualDeductionTotal(): float
    {
        return round((float) $this->manualDeductions()->lockForUpdate()->sum('amount'), 2);
    }

    /** The part of `deductions` that came from attendance (late arrivals). */
    public function attendanceDeductionTotal(): float
    {
        return max(0.0, round((float) $this->deductions - $this->manualDeductionTotal(), 2));
    }

    /**
     * The part of the manual deductions this report cannot take because the deductions
     * are more than its gross pay (net pay stops at ₱0). Happens when gross drops after
     * a deduction was recorded, e.g. an attendance day is deleted after a cash advance.
     */
    public function uncoveredManualDeductions(?float $manualTotal = null): float
    {
        $excess = max(0.0, round((float) $this->deductions - (float) $this->gross_pay, 2));

        return min($manualTotal ?? $this->manualDeductionTotal(), $excess);
    }

    /**
     * The stored figures for a gross pay and its attendance deductions, with the
     * manual deductions on top. Everything that rebuilds a report from attendance
     * goes through here, so a rebuild can never drop a manual deduction.
     *
     * @return array{deductions: float, net_pay: float}
     */
    public static function payAfterDeductions(float $grossPay, float $attendanceDeductions, float $manualDeductions): array
    {
        $deductions = round($attendanceDeductions + $manualDeductions, 2);

        return [
            'deductions' => $deductions,
            'net_pay' => round(max(0, $grossPay - $deductions), 2),
        ];
    }

    /** Re-total after a manual deduction was added, removed or moved here. */
    public function retotal(float $attendanceDeductions): void
    {
        $this->update(self::payAfterDeductions((float) $this->gross_pay, $attendanceDeductions, $this->manualDeductionTotal()));
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
