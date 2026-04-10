<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

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
        'status',
        'notes',
    ];
}
