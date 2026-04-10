<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'order_number',
        'sale_datetime',
        'cashier_user_id',
        'table_label',
        'order_type',
        'status',
        'sub_total',
        'discount_total',
        'tax_total',
        'grand_total',
        'paid_total',
        'change_total',
        'payment_method',
        'notes',
        'closed_at',
    ];
}
