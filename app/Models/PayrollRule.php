<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'grace_minutes',
        'standard_daily_hours',
        'overtime_threshold_minutes',
        'overtime_multiplier',
        'undertime_rounding_minutes',
        'late_penalty_per_minute',
        'absent_penalty_days',
        'required_clock_in_time',
        'first_deduction_time',
        'first_deduction_amount',
        'second_deduction_time',
        'second_deduction_amount',
        'third_deduction_time',
        'third_deduction_percent',
    ];
}
