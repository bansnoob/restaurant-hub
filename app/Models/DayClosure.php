<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DayClosure extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'closed_at_date',
        'closed_by_user_id',
        'closed_at',
        'opening_float',
        'cash_sales_total',
        'mixed_cash_total',
        'gcash_sales_total',
        'cash_expenses_total',
        'expected_cash',
        'counted_cash',
        'variance',
        'order_count',
        'expense_count',
        'auto_clocked_out_count',
        'notes',
    ];

    protected $casts = [
        'closed_at_date' => 'date',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'cash_sales_total' => 'decimal:2',
        'mixed_cash_total' => 'decimal:2',
        'gcash_sales_total' => 'decimal:2',
        'cash_expenses_total' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
