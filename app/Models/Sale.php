<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    /**
     * A sale carries GCash money in one of two shapes:
     *  - payment_method = 'gcash' — the whole grand_total was paid by GCash. `gcash_amount`
     *    is nullable and the POS does not reliably populate it for single-tender payments.
     *  - payment_method = 'mixed' — only the `gcash_amount` slice was paid by GCash.
     *
     * This mirrors the math in DayClosureController::computeTotals(), which is what
     * populates the stored day_closures.gcash_sales_total. Keep the two in agreement.
     */
    public const GCASH_AMOUNT_SQL = "CASE WHEN sales.payment_method = 'gcash' THEN sales.grand_total ELSE COALESCE(sales.gcash_amount, 0) END";

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
        'cash_amount',
        'gcash_amount',
        'notes',
        'closed_at',
    ];

    protected $casts = [
        'sale_datetime' => 'datetime',
        'closed_at' => 'datetime',
        'sub_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'change_total' => 'decimal:2',
        'cash_amount' => 'decimal:2',
        'gcash_amount' => 'decimal:2',
    ];

    /**
     * Completed sales that actually moved money through GCash. A `mixed` sale whose
     * gcash_amount is null or zero was settled entirely in cash and is excluded.
     */
    public function scopeGcashBearing(Builder $query): Builder
    {
        return $query
            ->where('sales.status', 'completed')
            ->where(function (Builder $inner) {
                $inner->where('sales.payment_method', 'gcash')
                    ->orWhere(function (Builder $mixed) {
                        $mixed->where('sales.payment_method', 'mixed')
                            ->where('sales.gcash_amount', '>', 0);
                    });
            });
    }

    /**
     * Sum the GCash portion of every sale matched by the given query.
     *
     * @param  Builder<Sale>  $query
     */
    public static function gcashAmountSum(Builder $query): float
    {
        return (float) $query->clone()
            ->toBase()
            ->selectRaw('COALESCE(SUM('.self::GCASH_AMOUNT_SQL.'), 0) as aggregate')
            ->value('aggregate');
    }

    /**
     * The GCash portion of this individual sale.
     */
    public function gcashValue(): float
    {
        if ($this->payment_method === 'gcash') {
            return (float) $this->grand_total;
        }

        return (float) ($this->gcash_amount ?? 0);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
