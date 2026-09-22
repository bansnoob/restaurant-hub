<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\SpecialExpense;
use App\Models\SpecialExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The select options the special-expense form needs, shared by every page that hosts it.
 *
 * The form now appears on two pages. Duplicating this would mean the Cash Report's copy
 * could silently drift from the Special Expenses page's — and the deactivated-option rule
 * below is exactly the kind of subtlety that gets lost in a second copy.
 */
class SpecialExpenseOptions
{
    private const MONTH_OPTIONS = 18;

    /**
     * @param  Builder<SpecialExpense>  $visible  the rows currently listed on the page
     * @return array{branches: \Illuminate\Support\Collection, categories: \Illuminate\Support\Collection, monthOptions: array<int, array{value: string, label: string}>}
     */
    public function forVisible(Builder $visible, string $currentMonth): array
    {
        // Include any branch or category a listed row still points at, even if it has since
        // been deactivated. Otherwise the edit drawer offers no option matching the row's
        // value, the browser selects the first option instead ('All branches' / 'No
        // category'), and saving an unrelated field silently reassigns the cost.
        $usedBranchIds = (clone $visible)->whereNotNull('branch_id')->distinct()->pluck('branch_id');
        $usedCategoryIds = (clone $visible)->whereNotNull('special_expense_category_id')
            ->distinct()->pluck('special_expense_category_id');

        return [
            'branches' => Branch::where('is_active', true)
                ->orWhereIn('id', $usedBranchIds)
                ->orderBy('name')->get(),
            'categories' => SpecialExpenseCategory::where('is_active', true)
                ->orWhereIn('id', $usedCategoryIds)
                ->orderBy('name')->get(),
            'monthOptions' => $this->monthOptions($currentMonth),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function monthOptions(string $selected): array
    {
        $newest = now()->startOfMonth();
        $oldest = $newest->copy()->subMonths(self::MONTH_OPTIONS - 1);

        $earliestData = SpecialExpense::min('period_month');
        if ($earliestData) {
            $earliest = Carbon::parse($earliestData)->startOfMonth();
            if ($earliest->lt($oldest)) {
                $oldest = $earliest;
            }
        }

        $selectedStart = Carbon::parse($selected)->startOfMonth();
        if ($selectedStart->lt($oldest)) {
            $oldest = $selectedStart;
        }
        if ($selectedStart->gt($newest)) {
            $newest = $selectedStart;
        }

        $options = [];
        $cursor = $newest->copy();
        while ($cursor->gte($oldest)) {
            $options[] = ['value' => $cursor->toDateString(), 'label' => $cursor->format('F Y')];
            $cursor = $cursor->copy()->subMonth();
        }

        return $options;
    }

    /**
     * Settlement-date ordering, newest first.
     *
     * The Special Expenses page filters to one month, so period_month is identical on every
     * row and ordering by it collapsed to id — entry order, not date order. paid_date is the
     * day the money actually moved; period_month is the fallback for a bill not yet settled.
     *
     * @param  Builder<SpecialExpense>  $query
     * @return Builder<SpecialExpense>
     */
    public function orderByPaidDate(Builder $query): Builder
    {
        return $query
            ->orderByRaw('COALESCE(paid_date, period_month) DESC')
            ->orderByDesc('id');
    }
}
