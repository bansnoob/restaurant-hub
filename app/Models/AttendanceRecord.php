<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'branch_id',
        'shift_id',
        'work_date',
        'clock_in_at',
        'clock_out_at',
        'break_minutes',
        'status',
        'notes',
        'captured_by_user_id',
    ];
}
