<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    /**
     * Where the cash came from. Only a drawer-paid expense belongs in a day's
     * closure: an outside-paid one left the safe, not the till, so charging it to
     * the day would report a shortage the cashier never caused.
     */
    public const PAID_FROM = ['drawer', 'outside'];

    protected $fillable = [
        'branch_id',
        'expense_category_id',
        'recorded_by_user_id',
        'expense_date',
        'reference_no',
        'vendor_name',
        'description',
        'amount',
        'payment_method',
        'paid_from',
        'status',
        'notes',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
