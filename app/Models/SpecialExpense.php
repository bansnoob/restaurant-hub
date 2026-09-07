<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Monthly overhead — rent, electricity, permits — deliberately kept out of the
 * `expenses` table so it can never reach the day-closure drawer reconciliation,
 * today's net income, or today's cash on hand. See the create migration for why.
 *
 * A row is dated by the month it BELONGS to (`period_month`, always the 1st),
 * not by when it was paid. `paid_date` carries the settlement date when known.
 */
class SpecialExpense extends Model
{
    use HasFactory;

    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'gcash', 'other'];

    protected $fillable = [
        'branch_id',
        'special_expense_category_id',
        'recorded_by_user_id',
        'period_month',
        'paid_date',
        'description',
        'vendor_name',
        'reference_no',
        'amount',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'date',
        'paid_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SpecialExpenseCategory::class, 'special_expense_category_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Restrict to one calendar month. Takes any date in the month and normalises
     * to the 1st, because `period_month` is only ever stored as a month start —
     * comparing against a mid-month date would match nothing.
     *
     * Column is table-qualified so the scope survives the category left-join used
     * by the breakdown query.
     */
    public function scopeForMonth(Builder $query, string|Carbon $month): Builder
    {
        $start = Carbon::parse(is_string($month) ? $month : $month->toDateString())->startOfMonth();

        // whereDate(), not a plain where(), and it must stay that way.
        //
        // The `date` cast writes through Laravel's grammar format, so the value
        // that lands in the column is driver-dependent: MySQL's DATE column
        // truncates it to `2026-09-01`, while SQLite — the driver every test
        // runs on — stores the literal `2026-09-01 00:00:00`. A plain equality
        // against `2026-09-01` therefore matches on production and silently
        // matches NOTHING in the test suite, which would green-light an
        // "overhead is isolated" run that only proves the query is broken.
        // whereDate() wraps the column in date() and is correct on both.
        //
        // The cost is that the period_month indexes cannot be used for this
        // predicate. That is deliberate and cheap: this table takes on the order
        // of ten rows a month, and the composite index still serves branch_id.
        return $query->whereDate('special_expenses.period_month', $start->toDateString());
    }

    /**
     * Restrict to a span of months, inclusive at both ends.
     */
    public function scopeBetweenMonths(Builder $query, string|Carbon $from, string|Carbon $to): Builder
    {
        $start = Carbon::parse(is_string($from) ? $from : $from->toDateString())->startOfMonth();
        $end = Carbon::parse(is_string($to) ? $to : $to->toDateString())->startOfMonth();

        // whereDate() for the same driver reason as scopeForMonth().
        return $query
            ->whereDate('special_expenses.period_month', '>=', $start->toDateString())
            ->whereDate('special_expenses.period_month', '<=', $end->toDateString());
    }
}
